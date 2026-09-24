<?php

namespace App\Livewire\Requests;

use App\Enums\Decision;
use App\Enums\RecommendationOutcome;
use App\Models\Classification;
use App\Models\GovernanceRoute;
use App\Models\ItRequest;
use App\Models\ReviewUnit;
use App\Models\Tier;
use App\Services\GovernanceService;
use App\Services\WorkflowService;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Request detail — the screen the project succeeds or fails on.
 *
 * Status timeline, approval trail, recommendations, documents, comments and audit
 * history for one request. If this screen is right, the rest follows.
 *
 * THE AUTHORISATION GAP THIS COMPONENT USED TO HAVE
 *
 * The route is behind `auth` only, so any signed-in user could open any request by
 * changing the id in the URL — and the request body holds budgets, vendor
 * arrangements and internal justifications. `mount()` now authorises through the
 * policy, deriving access from the record rather than from knowing the id.
 *
 * WHY 403 RATHER THAN 404 FOR A REQUEST THE USER MAY NOT SEE
 *
 * A 404 would be the stronger choice: a 403 confirms the record exists, so somebody
 * walking ids learns which ones are real. If this ever faces anyone outside the
 * organisation, that difference matters and the check should return 404 instead.
 *
 * Inside a single organisation the trade-off goes the other way. The users are
 * colleagues, a request is often shared by link, and "you do not have access to this
 * request" tells a legitimate person to ask for access — whereas 404 tells them the
 * link is broken and they go looking for a bug that is not there. Whichever is
 * chosen, it should be chosen deliberately rather than by leaving `authorize()` in
 * and describing it as something else.
 */
class Show extends Component
{
    /**
     * The request on screen.
     *
     * LOCKED: the client must not be able to reassign it. Without the lock, an
     * action could be run against a request other than the one that was authorised
     * in `mount()` — the authorisation would still have passed, for a different row.
     */
    #[Locked]
    public ?ItRequest $request = null;

    /** One line of feedback after an action. */
    public string $flash = '';

    // ---- Governance inputs -------------------------------------------------

    /** Tier the Governance Reviewer confirms or corrects. */
    public ?int $assess_tier_id = null;

    public ?int $assess_classification_id = null;

    public string $assess_reason = '';

    public string $assess_notes = '';

    /** Recommendation fields, for a technical reviewer filing a unit's position. */
    public ?int $recommend_unit_id = null;

    public string $recommend_outcome = '';

    public string $recommend_conditions = '';

    public string $recommend_evidence = '';

    /** Consolidation fields, for the HOU. */
    public ?int $consolidate_route_id = null;

    public string $consolidate_summary = '';

    /** Committee decision fields, for the Secretariat. */
    public string $committee_decision = '';

    public string $committee_conditions = '';

    public string $committee_comments = '';

    /** Which governance panel is open. */
    public string $panel = '';

    public function mount(ItRequest $request): void
    {
        $this->request = $request;

        $this->authorize('view', $request);

        // Pre-filled from the request, so the reviewer confirms rather than retypes.
        // A blank form would mean the reviewer's first act is to re-enter what is
        // already on the screen in front of them.
        $this->assess_tier_id = $request->tier_id ?? $request->proposed_tier_id;
        $this->assess_classification_id = $request->classification_id ?? $request->proposed_classification_id;
        $this->consolidate_route_id = $request->governance_route_id;
    }

    /** Open one of the governance panels, authorising it first. */
    public function openPanel(string $panel): void
    {
        $ability = match ($panel) {
            'assess' => 'assess',
            'recommend' => 'recommend',
            'consolidate' => 'consolidate',
            'committee' => 'recordCommitteeDecision',
            'close' => 'close',
            default => null,
        };

        if ($ability === null) {
            return;
        }

        // Authorised on OPEN as well as on submit, so a panel a user cannot use is
        // never rendered — the button and the panel and the action all agree.
        $this->authorize($ability, $this->request);

        $this->panel = $panel;
        $this->flash = '';
        $this->resetErrorBag();
    }

    public function closePanel(): void
    {
        $this->panel = '';
        $this->resetErrorBag();
    }

    /**
     * Submit the request into the approval chain.
     *
     * Re-authorised at the point of action, not only on load. A page left open can
     * outlive the permission that opened it — the request may have moved, or a role
     * may have been withdrawn.
     */
    public function submit(): void
    {
        $this->authorize('submit', $this->request);

        app(WorkflowService::class)->submit($this->request);

        $this->request->refresh();

        $this->flash = "Submitted to {$this->request->projectOwner?->name} for approval.";
    }

    /** Withdraw the request. */
    public function withdraw(): void
    {
        $this->authorize('withdraw', $this->request);

        app(WorkflowService::class)->close($this->request, 'withdrawn');

        $this->request->refresh();

        $this->flash = 'Request withdrawn.';
    }

    /**
     * Resubmit a request that was returned for amendment (BR-007).
     *
     * Re-enters the stage that returned it rather than restarting the chain, so the
     * requestor is not sent back through approvals that already passed and the
     * approvers do not re-read decisions they have already made.
     */
    public function resubmit(): void
    {
        $this->authorize('resubmit', $this->request);

        $resumed = app(WorkflowService::class)->resubmit($this->request);

        $this->request->refresh();

        /*
         * The message names the stage, because "resubmitted" alone leaves the
         * requestor unsure whether it went back to the beginning. Naming it answers
         * the question they are actually asking: did my earlier approval survive?
         */
        $this->flash = $resumed
            ? "Resubmitted. It is back with the {$resumed->label()}."
            : 'Resubmitted.';
    }

    // ---- Governance actions -------------------------------------------------

    /**
     * Record the completeness assessment and move into technical recommendation.
     *
     * The service enforces the rules (tier and classification required, reason
     * required when they change). The validation here exists to put the message
     * beside the field that caused it.
     */
    public function assess(): void
    {
        $this->authorize('assess', $this->request);

        $this->validate([
            'assess_tier_id' => ['required', 'integer', 'exists:tiers,id'],
            'assess_classification_id' => ['required', 'integer', 'exists:classifications,id'],
            'assess_reason' => ['nullable', 'string', 'max:2000'],
            'assess_notes' => ['nullable', 'string', 'max:5000'],
        ], attributes: [
            'assess_tier_id' => 'tier',
            'assess_classification_id' => 'classification',
            'assess_reason' => 'reason for the change',
            'assess_notes' => 'review notes',
        ]);

        try {
            app(GovernanceService::class)->assess(
                request: $this->request,
                assessor: auth()->user(),
                tierId: (int) $this->assess_tier_id,
                classificationId: (int) $this->assess_classification_id,
                reclassificationReason: $this->assess_reason ?: null,
                notes: $this->assess_notes ?: null,
            );
        } catch (\RuntimeException $e) {
            // The service refuses what the form did not catch. Surfaced as a message
            // rather than a 500: the reason is always actionable.
            $this->addError('assess_reason', $e->getMessage());

            return;
        }

        $this->request->refresh();

        $outstanding = app(GovernanceService::class)->outstandingUnits($this->request);

        $this->panel = '';
        $this->flash = $outstanding->isEmpty()
            ? 'Assessed. No reviewing units are assigned to this classification.'
            : 'Assessed. Awaiting recommendations from: '.$outstanding->pluck('name')->join(', ').'.';
    }

    /** File a reviewing unit's recommendation (FR-008). */
    public function recommend(): void
    {
        $this->authorize('recommend', $this->request);

        $this->validate([
            'recommend_unit_id' => ['required', 'integer', 'exists:review_units,id'],
            'recommend_outcome' => ['required', 'string'],
            'recommend_conditions' => ['nullable', 'string', 'max:2000'],
            'recommend_evidence' => ['nullable', 'string', 'max:5000'],
        ], attributes: [
            'recommend_unit_id' => 'reviewing unit',
            'recommend_outcome' => 'recommendation',
            'recommend_conditions' => 'conditions',
            'recommend_evidence' => 'reason',
        ]);

        $unit = ReviewUnit::findOrFail($this->recommend_unit_id);

        try {
            app(GovernanceService::class)->recordRecommendation(
                request: $this->request,
                reviewer: auth()->user(),
                unit: $unit,
                outcome: RecommendationOutcome::from($this->recommend_outcome),
                conditions: $this->recommend_conditions ?: null,
                evidence: $this->recommend_evidence ?: null,
            );
        } catch (\RuntimeException $e) {
            $this->addError('recommend_outcome', $e->getMessage());

            return;
        }

        $this->request->refresh();

        $outstanding = app(GovernanceService::class)->outstandingUnits($this->request);

        $this->panel = '';
        $this->reset('recommend_outcome', 'recommend_conditions', 'recommend_evidence');
        $this->flash = $outstanding->isEmpty()
            ? 'Recommendation recorded. Every assigned unit has now filed, so the request has moved to consolidation.'
            : 'Recommendation recorded. Still awaiting: '.$outstanding->pluck('name')->join(', ').'.';
    }

    /** Consolidate and set the governance route. */
    public function consolidate(): void
    {
        $this->authorize('consolidate', $this->request);

        $this->validate([
            'consolidate_route_id' => ['required', 'integer', 'exists:governance_routes,id'],
            'consolidate_summary' => ['required', 'string', 'min:20', 'max:5000'],
        ], attributes: [
            'consolidate_route_id' => 'governance route',
            'consolidate_summary' => 'consolidation summary',
        ]);

        try {
            $consolidation = app(GovernanceService::class)->consolidate(
                request: $this->request,
                hou: auth()->user(),
                governanceRouteId: (int) $this->consolidate_route_id,
                summary: $this->consolidate_summary,
            );
        } catch (\RuntimeException $e) {
            $this->addError('consolidate_summary', $e->getMessage());

            return;
        }

        $this->request->refresh();

        $this->panel = '';
        $this->flash = $consolidation->requiresCommittee()
            ? 'Consolidated. The Full route requires an IT Investment Committee decision.'
            : 'Consolidated. The route does not require a committee, so the request is approved and awaiting closure.';
    }

    /** Record the committee's decision. */
    public function recordCommitteeDecision(): void
    {
        $this->authorize('recordCommitteeDecision', $this->request);

        $this->validate([
            'committee_decision' => ['required', 'string'],
            'committee_conditions' => ['nullable', 'string', 'max:2000'],
            'committee_comments' => ['nullable', 'string', 'max:5000'],
        ], attributes: [
            'committee_decision' => 'committee decision',
            'committee_conditions' => 'conditions',
            'committee_comments' => 'comment',
        ]);

        try {
            app(GovernanceService::class)->recordCommitteeDecision(
                request: $this->request,
                recorder: auth()->user(),
                decision: Decision::from($this->committee_decision),
                conditions: $this->committee_conditions ?: null,
                comments: $this->committee_comments ?: null,
            );
        } catch (\RuntimeException $e) {
            $this->addError('committee_decision', $e->getMessage());

            return;
        }

        $this->request->refresh();

        $this->panel = '';
        $this->reset('committee_decision', 'committee_conditions', 'committee_comments');
        $this->flash = 'Committee decision recorded.';
    }

    /** Close the request, once BR-006 is satisfied. */
    public function closeRequest(): void
    {
        $this->authorize('close', $this->request);

        try {
            app(GovernanceService::class)->close($this->request, auth()->user());
        } catch (\RuntimeException $e) {
            /*
             * The refusal lists every blocker. Shown in full rather than summarised,
             * because "cannot close" with no reason is a dead end and the list is the
             * whole value of the guard.
             */
            $this->addError('close', $e->getMessage());

            return;
        }

        $this->request->refresh();

        $this->panel = '';
        $this->flash = 'Request closed.';
    }

    public function render()
    {
        /*
         * Everything the screen shows, eager-loaded.
         *
         * The timeline, trail, comments and audit tab each touch a relation, and this
         * host has no worker process — so an N+1 here is paid on every page view by
         * the person waiting for it.
         */
        $this->request->load([
            'requestor:id,name',
            'projectOwner:id,name',
            'projectSponsor:id,name',
            'department:id,name',
            'division:id,name',
            'proposedTier:id,name',
            'proposedClassification:id,name',
            'tier:id,name',
            'classification:id,name',
            'governanceRoute:id,name',
            'histories' => fn ($q) => $q->with('performedBy:id,name')->orderBy('created_at'),
            'approvalTasks' => fn ($q) => $q->with('approver:id,name')->orderBy('created_at'),
            'comments' => fn ($q) => $q->with('user:id,name')->orderBy('created_at'),
            'attachments',

            // ---- Governance ----------------------------------------------------
            'completenessAssessment' => fn ($q) => $q->with('assessedBy:id,name'),
            // Every version, not only the current one — BR-005 exists so the committee
            // can see a unit's position AND how it changed.
            'recommendations' => fn ($q) => $q
                ->with(['reviewUnit:id,name', 'reviewer:id,name'])
                ->orderBy('review_unit_id')
                ->orderBy('version_no'),
            'consolidation' => fn ($q) => $q->with(['consolidatedBy:id,name', 'governanceRoute:id,name,requires_committee']),
            'committeeDecision' => fn ($q) => $q->with('recordedBy:id,name'),
        ]);

        $service = app(GovernanceService::class);

        /*
         * The units this reviewer may file for.
         *
         * Narrowed to the units ASSIGNED to this request's classification AND that the
         * user belongs to. Offering every unit would let a reviewer pick one they are
         * not in and be refused by the service — a message the form could have avoided
         * by not offering the option.
         */
        $assignedUnits = $service->unitsFor((int) $this->request->classification_id);
        $myUnits = $assignedUnits->filter(
            fn ($unit) => auth()->user()->reviewUnits->contains('id', $unit->id)
                || auth()->user()->isAdministrator()
        )->values();

        return view('livewire.requests.show', [
            'canDecide' => auth()->user()->can('decide', $this->request),
            'canEdit' => auth()->user()->can('update', $this->request),
            'canSubmit' => auth()->user()->can('submit', $this->request),
            'canResubmit' => auth()->user()->can('resubmit', $this->request),
            'canWithdraw' => auth()->user()->can('withdraw', $this->request),
            'pendingTask' => $this->request->pendingApprovalTask,

            'canAssess' => auth()->user()->can('assess', $this->request),
            'canRecommend' => auth()->user()->can('recommend', $this->request),
            'canConsolidate' => auth()->user()->can('consolidate', $this->request),
            'canCommittee' => auth()->user()->can('recordCommitteeDecision', $this->request),
            'canClose' => auth()->user()->can('close', $this->request),

            'tiers' => Tier::orderBy('sort_order')->get(['id', 'name']),
            'classifications' => Classification::orderBy('name')->get(['id', 'name']),
            'routes' => GovernanceRoute::orderBy('sort_order')->get(['id', 'name', 'requires_committee', 'description']),
            'outstandingUnits' => $service->outstandingUnits($this->request),
            'assignedUnits' => $assignedUnits,
            'myUnits' => $myUnits,
            'closureBlockers' => $service->closureBlockers($this->request),
            'recommendationOutcomes' => RecommendationOutcome::options(),
            'committeeDecisions' => [
                Decision::Approved->value => Decision::Approved->label(),
                Decision::ApprovedWithConditions->value => Decision::ApprovedWithConditions->label(),
                Decision::Rejected->value => Decision::Rejected->label(),
                Decision::Returned->value => Decision::Returned->label(),
            ],
        ])->layout('components.layouts.app', ['title' => $this->request->title]);
    }
}
