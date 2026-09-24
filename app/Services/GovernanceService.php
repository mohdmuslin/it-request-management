<?php

namespace App\Services;

use App\Enums\Decision;
use App\Enums\RecommendationOutcome;
use App\Enums\RequestStatus;
use App\Enums\WorkflowStage;
use App\Models\Classification;
use App\Models\CommitteeDecision;
use App\Models\CompletenessAssessment;
use App\Models\GovernanceRoute;
use App\Models\ItRequest;
use App\Models\Recommendation;
use App\Models\RecommendationConsolidation;
use App\Models\ReviewUnit;
use App\Models\User;
use App\Models\WorkflowHistory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The governance phase: assessment, recommendations, consolidation, committee.
 *
 * WHY THIS IS SEPARATE FROM WorkflowService AND WorkflowDecisionService
 *
 * Each answers a different question. `WorkflowService` moves a request between
 * stages. `WorkflowDecisionService` records what an approver decided. This decides
 * what the request IS — its tier, classification, route — and gathers the technical
 * opinions that inform it.
 *
 * WHAT THIS SERVICE IS RESPONSIBLE FOR THAT NOTHING ELSE IS
 *
 * The transition from "approvals finished" to "closed", which is four distinct acts
 * with different actors, and two of which can be refused (BR-006 at closure, and the
 * committee stage for a route that does not require it).
 */
class GovernanceService
{
    public function __construct(
        private readonly WorkflowService $workflow,
        private readonly WorkflowDecisionService $decisionService,
        private readonly NotificationService $notifications,
    ) {}

    // ---- Completeness review ------------------------------------------------

    /**
     * Record the assessment and move the request into technical recommendation.
     *
     * WHY TIER AND CLASSIFICATION ARE REQUIRED HERE
     *
     * The recommendation rows are created FROM the classification — which units must
     * review depends on it. Assessing a request without settling the classification
     * would mean creating recommendation rows for the wrong units, and the error
     * would not surface until the committee read a recommendation from a unit that
     * should never have been asked.
     */
    public function assess(
        ItRequest $request,
        User $assessor,
        int $tierId,
        int $classificationId,
        ?string $reclassificationReason = null,
        ?string $notes = null,
    ): CompletenessAssessment {
        $this->assertStage($request, WorkflowStage::CompletenessReview, 'assess');

        $proposedTier = $request->proposed_tier_id;
        $proposedClassification = $request->proposed_classification_id;

        $changed = ($proposedTier !== null && $proposedTier !== $tierId)
            || ($proposedClassification !== null && $proposedClassification !== $classificationId);

        /*
         * A reclassification must be explained.
         *
         * Only required when governance DISAGREES with the requestor. Asking for a
         * reason on every assessment would train people to type "." — requiring it
         * only when something changed means the field carries information exactly
         * when it matters, and the requestor gets a real answer to "why was my
         * request reclassified?".
         */
        if ($changed && blank($reclassificationReason)) {
            throw new \RuntimeException(
                'This assessment changes the tier or classification the requestor proposed. '
                .'Record why, so the requestor can see the reason for the change.'
            );
        }

        return DB::transaction(function () use (
            $request, $assessor, $tierId, $classificationId,
            $reclassificationReason, $notes, $changed,
        ) {
            $assessment = CompletenessAssessment::updateOrCreate(
                ['request_id' => $request->id],
                [
                    'assessed_by' => $assessor->id,
                    'tier_id' => $tierId,
                    'classification_id' => $classificationId,
                    'reclassification_reason' => $changed ? $reclassificationReason : null,
                    'notes' => $notes,
                    'completed_at' => now(),
                ],
            );

            /*
             * The ASSIGNED values go on the request; the PROPOSED ones stay untouched.
             *
             * Both are kept so the audit trail can show that a value changed and what
             * it changed from — which is what the trail is for when a requestor later
             * disputes a classification.
             */
            $request->forceFill([
                'tier_id' => $tierId,
                'classification_id' => $classificationId,
                'status' => RequestStatus::PendingTechnicalRecommendation->value,
                'current_stage' => WorkflowStage::TechnicalRecommendation->value,
            ])->save();

            $units = $this->unitsFor($classificationId);

            $this->recordTransition(
                $request,
                'assess',
                WorkflowStage::CompletenessReview->value,
                WorkflowStage::TechnicalRecommendation->value,
                $assessor->id,
                $notes,
            );

            /*
             * No approval task is created for this stage.
             *
             * The technical stage is complete when every assigned UNIT has filed, not
             * when a person decides — so a task would be the wrong shape: it would sit
             * with one approver while three units were meant to work in parallel. The
             * stage advances from `recordRecommendation()` once the last unit is in.
             */

            return $assessment;
        });
    }

    /** The units that must review a request of this classification. */
    public function unitsFor(int $classificationId): Collection
    {
        $classification = Classification::with(['reviewUnits' => function ($q) {
            $q->orderBy('classification_review_units.sort_order');
        }])->find($classificationId);

        return $classification?->reviewUnits ?? collect();
    }

    // ---- Recommendations (FR-008, BR-005) -----------------------------------

    /**
     * A unit files or revises its recommendation.
     *
     * NEVER UPDATES IN PLACE. A revision inserts `version_no + 1` and points
     * `supersedes_id` at the row it replaced, so the committee sees the final
     * position AND the history that produced it — a unit that moved from "not
     * recommended" to "recommended with conditions" has told the committee something
     * important by doing so.
     */
    public function recordRecommendation(
        ItRequest $request,
        User $reviewer,
        ReviewUnit $unit,
        RecommendationOutcome $outcome,
        ?string $conditions = null,
        ?string $evidence = null,
    ): Recommendation {
        $this->assertStage($request, WorkflowStage::TechnicalRecommendation, 'record a recommendation');

        /*
         * The reviewer must belong to the unit they are filing for.
         *
         * Without this, any technical reviewer could file on behalf of any unit —
         * and since the whole point of FR-008 is three INDEPENDENT unit opinions,
         * a single reviewer filing all three would produce a consolidation that
         * looked unanimous while only one unit had actually looked at the request.
         */
        $belongs = $reviewer->reviewUnits()->where('review_units.id', $unit->id)->exists()
            || $reviewer->isAdministrator();

        if (! $belongs) {
            throw new \RuntimeException(
                "You are not a member of {$unit->name}, so you cannot file its recommendation."
            );
        }

        if ($outcome->requiresConditions() && blank($conditions)) {
            throw new \RuntimeException(
                'A recommendation with conditions must record the conditions — '
                .'otherwise they exist only in your memory, and the committee cannot weigh them.'
            );
        }

        if ($outcome->requiresReason() && blank($evidence)) {
            throw new \RuntimeException(
                'Advising against a request requires the reason. The IT HOU consolidates from '
                .'these, and the committee needs something to weigh against the requestor\'s case.'
            );
        }

        return DB::transaction(function () use ($request, $reviewer, $unit, $outcome, $conditions, $evidence) {
            $previous = Recommendation::query()
                ->forRequest($request->id)
                ->where('review_unit_id', $unit->id)
                ->current()
                ->first();

            $recommendation = Recommendation::create([
                'request_id' => $request->id,
                'review_unit_id' => $unit->id,
                'reviewer_id' => $reviewer->id,
                'recommendation' => $outcome->value,
                'conditions' => $outcome->requiresConditions() ? $conditions : null,
                'evidence' => $evidence,
                'version_no' => ($previous?->version_no ?? 0) + 1,
                'supersedes_id' => $previous?->id,
                'submitted_at' => now(),
            ]);

            $this->recordTransition(
                $request,
                $previous ? 'recommendation_revised' : 'recommendation_filed',
                WorkflowStage::TechnicalRecommendation->value,
                WorkflowStage::TechnicalRecommendation->value,
                $reviewer->id,
                "{$unit->name}: {$outcome->label()}",
            );

            // Advances the request if this was the last unit outstanding.
            $this->advanceIfAllRecommendationsIn($request);

            return $recommendation;
        });
    }

    /**
     * Units that have not yet filed a current recommendation.
     *
     * Used by the workspace to show what is outstanding, and by the advance check.
     * A unit is outstanding if it has NO current recommendation — a unit that filed
     * and then revised is not outstanding, because its position is current.
     */
    public function outstandingUnits(ItRequest $request): Collection
    {
        $assigned = $this->unitsFor((int) $request->classification_id);

        if ($assigned->isEmpty()) {
            return collect();
        }

        $filed = Recommendation::query()
            ->forRequest($request->id)
            ->current()
            ->pluck('review_unit_id')
            ->all();

        return $assigned->reject(fn ($unit) => in_array($unit->id, $filed, true))->values();
    }

    /**
     * Advance to consolidation once every assigned unit has filed.
     *
     * WHY THIS IS A SYSTEM TRANSITION WITH NO ACTOR
     *
     * Nothing decided it — the condition became true. `performed_by` is null, which
     * the history table allows deliberately: a fabricated actor here would put a
     * name against a decision nobody made.
     */
    private function advanceIfAllRecommendationsIn(ItRequest $request): void
    {
        if ($this->outstandingUnits($request)->isNotEmpty()) {
            return;
        }

        $request->forceFill([
            'status' => RequestStatus::PendingConsolidation->value,
            'current_stage' => WorkflowStage::Consolidation->value,
        ])->save();

        $this->recordTransition(
            $request,
            'all_recommendations_in',
            WorkflowStage::TechnicalRecommendation->value,
            WorkflowStage::Consolidation->value,
            // Nobody decided this — the condition became true.
            null,
            'Every assigned reviewing unit has filed.',
        );

        app(NotificationService::class)->notifyStageReached($request, WorkflowStage::Consolidation);
    }

    // ---- Consolidation ------------------------------------------------------

    /**
     * Consolidate the recommendations and set the governance route.
     *
     * THE ROUTE IS THE OUTPUT OF THIS ACT, not an input.
     *
     * It is chosen here by the HOU after reading the recommendations, and stored on
     * both the request and the consolidation row so the reasoning survives a later
     * question about why a request did or did not reach the committee.
     */
    public function consolidate(
        ItRequest $request,
        User $hou,
        int $governanceRouteId,
        string $summary,
    ): RecommendationConsolidation {
        $this->assertStage($request, WorkflowStage::Consolidation, 'consolidate');

        $route = GovernanceRoute::findOrFail($governanceRouteId);

        /*
         * A route must have been agreed before it can be applied.
         *
         * `requires_committee` is read from the row rather than derived from the code,
         * so an administrator can change the routing rule without a release — and
         * this check therefore reads the database, not a `match` on the enum.
         */
        $needsCommittee = (bool) $route->requires_committee;

        return DB::transaction(function () use ($request, $hou, $route, $summary, $needsCommittee) {
            $consolidation = RecommendationConsolidation::updateOrCreate(
                ['request_id' => $request->id],
                [
                    'consolidated_by' => $hou->id,
                    'summary' => $summary,
                    'governance_route_id' => $route->id,
                    'consolidated_at' => now(),
                ],
            );

            $nextStage = $needsCommittee
                ? WorkflowStage::CommitteeDecision
                : WorkflowStage::Closure;

            $nextStatus = $needsCommittee
                ? RequestStatus::PendingCommitteeDecision
                : RequestStatus::Approved;

            $request->forceFill([
                'governance_route_id' => $route->id,
                'status' => $nextStatus->value,
                'current_stage' => $nextStage->value,
            ])->save();

            $this->recordTransition(
                $request,
                'consolidate',
                WorkflowStage::Consolidation->value,
                $nextStage->value,
                $hou->id,
                $summary,
            );

            if ($needsCommittee) {
                // A task, because here a PERSON decides — unlike the technical stage,
                // which completes when the units have filed.
                $this->workflow->createApprovalTask($request, WorkflowStage::CommitteeDecision);
            } else {
                /*
                 * No committee. The request is approved and waits for closure.
                 *
                 * Deliberately NOT closed here. BR-006 requires all mandatory
                 * decisions and documentation before closure, and an approval is not
                 * a closure — closing automatically would mean the documentation check
                 * never runs.
                 */
                $request->forceFill(['closed_at' => null])->save();
            }

            return $consolidation;
        });
    }

    // ---- Committee ----------------------------------------------------------

    /**
     * Record the committee's decision.
     *
     * ONLY AVAILABLE WHEN THE ROUTE REQUIRES IT. A committee decision on a Light-route
     * request would be a decision by a body with no authority over it, and it would
     * look entirely legitimate on the screen.
     */
    public function recordCommitteeDecision(
        ItRequest $request,
        User $recorder,
        Decision $decision,
        ?string $conditions = null,
        ?string $comments = null,
    ): CommitteeDecision {
        $route = $request->governanceRoute;

        if (! $route) {
            throw new \RuntimeException(
                "{$request->request_no} has no governance route, so a committee decision cannot apply. "
                .'The route is set during consolidation.'
            );
        }

        if (! $route->requires_committee) {
            throw new \RuntimeException(
                "{$request->request_no} is on the {$route->name} route, which does not require a "
                .'committee decision. Decisions on this request are complete.'
            );
        }

        $this->assertStage($request, WorkflowStage::CommitteeDecision, 'record a committee decision');

        if ($decision->requiresComment() && blank($comments)) {
            throw new \RuntimeException(
                "A comment is required when the committee decision is {$decision->label()}."
            );
        }

        if ($decision->requiresConditions() && blank($conditions)) {
            throw new \RuntimeException(
                'Approving with conditions requires the conditions to be recorded.'
            );
        }

        return DB::transaction(function () use ($request, $recorder, $decision, $conditions, $comments) {
            $record = CommitteeDecision::updateOrCreate(
                ['request_id' => $request->id],
                [
                    'recorded_by' => $recorder->id,
                    'decision' => $decision->value,
                    'conditions' => $decision->requiresConditions() ? $conditions : null,
                    'decided_at' => now(),
                ],
            );

            if ($decision === Decision::Returned) {
                $this->workflow->returnForAmendment($request, $comments ?? '', $decision->value);

                return $record;
            }

            $status = match ($decision) {
                Decision::Approved => RequestStatus::Approved,
                Decision::ApprovedWithConditions => RequestStatus::ApprovedWithConditions,
                Decision::Rejected => RequestStatus::NotRecommended,
                Decision::Returned => RequestStatus::ReturnedForAmendment,
            };

            $request->forceFill([
                'status' => $status->value,
                'current_stage' => WorkflowStage::Closure->value,
                'outcome' => $decision->closureOutcome(),
                // A rejection ends the request; an approval waits for closure.
                'closed_at' => $decision === Decision::Rejected ? now() : null,
            ])->save();

            $this->recordTransition(
                $request,
                'committee_'.$decision->value,
                WorkflowStage::CommitteeDecision->value,
                WorkflowStage::Closure->value,
                $recorder->id,
                $comments,
            );

            return $record;
        });
    }

    // ---- Closure (BR-006) ---------------------------------------------------

    /**
     * Whether closure is permitted, and if not, what is missing.
     *
     * Returns the BLOCKERS rather than throwing, so the closure screen can list them.
     * A single exception would say "cannot close" without saying what to do, which
     * on a governance screen is the difference between a fixable gap and a dead end.
     *
     * @return array<int, string>
     */
    public function closureBlockers(ItRequest $request): array
    {
        $blockers = [];

        // 1. Tier and classification must be settled.
        if (! $request->tier_id || ! $request->classification_id) {
            $blockers[] = 'The completeness review is not finished — tier and classification are not set.';
        }

        // 2. The route must be set, which only consolidation does.
        if (! $request->governance_route_id) {
            $blockers[] = 'The governance route has not been set. Consolidation determines it.';
        }

        // 3. Every assigned unit must have a current recommendation.
        if ($request->current_stage !== null) {
            $outstanding = $this->outstandingUnits($request);

            if ($outstanding->isNotEmpty()) {
                $names = $outstanding->pluck('name')->join(', ');
                $blockers[] = "Technical recommendations are outstanding from: {$names}.";
            }
        }

        /*
         * 4. A committee decision is required when the route demands one.
         *
         * `governanceRoute`, NOT `governance_route`.
         *
         * The column is `governance_route_id` but the RELATION is `governanceRoute()`,
         * and Eloquent resolves relations by method name. Accessing the snake_case form
         * does not fail — it returns null, so this condition was silently false on every
         * Full-route request and the committee decision was never checked.
         *
         * The line looked correct, the route data was correct, and the only symptom was
         * that closure required one fewer thing than it should have. A request could
         * have been closed without the committee ever deciding, which is the single
         * outcome the Full route exists to prevent.
         */
        if ($request->governanceRoute?->requires_committee && ! $request->committeeDecision) {
            $blockers[] = 'The governance route requires an IT Investment Committee decision, and none is recorded.';
        }

        // 5. No decision may be outstanding.
        if ($task = $request->pendingApprovalTask) {
            $blockers[] = "Approval task at '{$task->stage}' is still awaiting a decision.";
        }

        return $blockers;
    }

    /** Whether the request can be closed right now. */
    public function canClose(ItRequest $request): bool
    {
        return $this->closureBlockers($request) === [];
    }

    /**
     * Close the request.
     *
     * BR-006 in one guard. The blockers are checked here as well as in the UI because
     * a view can be bypassed, and the specific failures are enumerated rather than
     * collapsed into a boolean so the refusal is actionable.
     */
    public function close(ItRequest $request, User $closer, ?string $outcome = null): void
    {
        $blockers = $this->closureBlockers($request);

        if ($blockers) {
            throw new \RuntimeException(
                "{$request->request_no} cannot be closed yet:\n- ".implode("\n- ", $blockers)
            );
        }

        DB::transaction(function () use ($request, $closer, $outcome) {
            $from = $request->current_stage;

            $request->forceFill([
                'status' => RequestStatus::Closed->value,
                'current_stage' => WorkflowStage::Closure->value,
                // Set only if something did not already, so a committee outcome
                // recorded earlier is not overwritten by the closer.
                'outcome' => $outcome ?? $request->outcome ?? 'closed',
                'closed_at' => now(),
            ])->save();

            $this->recordTransition(
                $request,
                'close',
                $from,
                WorkflowStage::Closure->value,
                $closer->id,
                'All mandatory decisions and documentation are recorded.',
            );
        });
    }

    // ---- Helpers ------------------------------------------------------------

    /**
     * Refuse an action that does not belong to the request's current stage.
     *
     * The alternative is an action that succeeds against a request in the wrong
     * stage — a committee decision on a request that never reached the committee, for
     * instance — and every one of those produces a record that looks legitimate.
     */
    private function assertStage(ItRequest $request, WorkflowStage $expected, string $action): void
    {
        $current = $request->current_stage;

        if ($current !== $expected->value) {
            $actual = $current
                ? (WorkflowStage::tryFrom($current)?->label() ?? $current)
                : ($request->statusEnum()->label().' (no stage — it may be returned for amendment)');

            throw new \RuntimeException(
                "Cannot {$action} on {$request->request_no}: it is at '{$actual}', not '{$expected->label()}'."
            );
        }
    }

    /**
     * Write a transition row.
     *
     * `$performedBy` is REQUIRED and has no default, and null means "nobody" — the
     * correct answer for a system transition such as "every assigned unit has filed".
     *
     * An earlier signature defaulted it to `auth()->id()`, which meant an automatic
     * transition would have been attributed to whoever happened to trigger it. Making
     * the parameter mandatory forces every call site to state who acted, so the
     * distinction between a human decision and a condition becoming true cannot be
     * lost by omission.
     */
    private function recordTransition(
        ItRequest $request,
        string $action,
        ?string $fromStage,
        string $toStage,
        ?int $performedBy,
        ?string $remarks = null,
    ): void {
        WorkflowHistory::create([
            'request_id' => $request->id,
            'from_stage' => $fromStage,
            'to_stage' => $toStage,
            'action' => $action,
            'performed_by' => $performedBy,
            'remarks' => $remarks,
        ]);
    }
}
