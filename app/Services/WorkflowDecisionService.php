<?php

namespace App\Services;

use App\Enums\Decision;
use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Enums\WorkflowStage;
use App\Models\ApprovalTask;
use App\Models\Delegation;
use App\Models\ItRequest;
use App\Models\Tier;
use App\Models\User;
use App\Models\WorkflowHistory;
use App\Models\WorkflowStage as WorkflowStageModel;
use Illuminate\Support\Facades\DB;

/**
 * Decisions on approval tasks.
 *
 * WHY THIS IS SEPARATE FROM WorkflowService
 *
 * WorkflowService moves a request between stages. This decides whether a move is
 * allowed at all, and records who decided. Keeping them apart means the transition
 * rules can be tested without an approver, and the decision rules without a chain —
 * and both classes stay short enough to read.
 *
 * THE ONE INVARIANT
 *
 * A decision is recorded in ONE transaction that: marks the task decided, moves the
 * request, writes the history row, and returns any delegation to its original
 * approver. A partial application of those four is how a request ends up in a stage
 * with an undecided task, or decided with no record of who decided it.
 */
class WorkflowDecisionService
{
    public function __construct(
        private readonly WorkflowService $workflow,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Record a decision on a request's current stage.
     *
     * @throws \RuntimeException when the request has no task awaiting a decision
     */
    public function decide(
        ItRequest $request,
        User $actor,
        Decision $decision,
        ?string $comments = null,
        /*
         * A STRING, not an array.
         *
         * This was declared `array $conditions = []` while every single caller passed a
         * string — the approvals screen, the committee path, and every test. PHP threw a
         * TypeError the moment somebody chose "approved with conditions", so the one
         * decision type that carries conditions was the only one that could not be made.
         *
         * The column is `text`, conditions are a sentence rather than a list, and
         * `CommitteeDecision` already took a string — so the type on this one method was
         * the outlier. Changing it to match everything else is the fix; changing the
         * callers would have meant inventing a structure for a sentence.
         */
        ?string $conditions = null,
    ): ApprovalTask {
        $task = $request->pendingApprovalTask;

        if (! $task) {
            /*
             * Refused rather than silently tolerated.
             *
             * Two ways to reach this: the page was left open past somebody else's
             * decision, or the request has not been submitted. Both mean the decision
             * the user is looking at no longer applies, and recording it would
             * overwrite a decision already made.
             */
            throw new \RuntimeException(
                "{$request->request_no} has no decision awaiting at this stage. "
                .'It may already have been decided — reload the page to see the current state.'
            );
        }

        $this->assertDecisionIsAllowed($decision, $comments, $conditions);

        $delegation = $this->delegationFor($task, $actor);

        $decided = DB::transaction(function () use ($request, $task, $actor, $decision, $comments, $conditions, $delegation) {
            $task->forceFill([
                'decision' => $decision->value,
                'comments' => $comments,
                'decided_at' => now(),

                /*
                 * The acting approver is recorded in `approver_id` and the absent one
                 * in `delegated_from_id`.
                 *
                 * Deliberately not the other way round. `approver_id` answers "who
                 * made this decision", and the history view shows the task's approver
                 * as the decision maker — putting the absent approver there would
                 * attribute the act to somebody who was not present.
                 */
                'approver_id' => $actor->id,
                'delegated_from_id' => $delegation?->approver_id,

                /*
                 * Stored on the TASK, where the decision lives.
                 *
                 * An approval with conditions and no stored conditions is a
                 * contradiction: the condition would exist only in the comment text,
                 * which is prose the closure screen cannot cite and nobody can tick
                 * off. Storing it here keeps it attached to the decision that created
                 * it.
                 */
                'conditions' => $conditions ?: null,
            ])->save();

            $this->applyDecision($request, $task, $decision, $comments);

            return $task->fresh();
        });

        /*
         * Notified AFTER the transaction has committed.
         *
         * The record is the decision; the email is a courtesy. Sending inside the
         * transaction would let a slow or failing SMTP server roll back a decision
         * that was legitimately made — which is the wrong way round, and on this host
         * (no worker, no shell) the most likely failure mode.
         */
        if (config('itrequest.queue.notify_on_decision')) {
            $this->notifications->notifyDecision($decided);
        }

        return $decided;
    }

    /**
     * Refuse a decision that the business rules do not permit.
     *
     * BR-002 requires a comment on Return and Reject: without one the requestor has
     * nothing to act on, and "returned" with no reason is indistinguishable from a
     * mistake. Conditions on "approved with conditions" are the same argument — the
     * condition would exist only in somebody's memory.
     *
     * Checked here as well as in the form. The form is a convenience; this is the
     * rule.
     */
    private function assertDecisionIsAllowed(Decision $decision, ?string $comments, ?string $conditions): void
    {
        if ($decision->requiresComment() && blank($comments)) {
            throw new \RuntimeException(
                "A comment is required when a request is {$decision->label()}. "
                .'The requestor cannot act on a decision they cannot read.'
            );
        }

        if ($decision->requiresConditions() && blank($conditions)) {
            throw new \RuntimeException(
                'Approving with conditions requires the conditions to be recorded. '
                .'An approval with conditions and no conditions is a contradiction.'
            );
        }
    }

    /**
     * The delegation that authorises this actor, if they are acting for somebody.
     *
     * Null when the actor is the task's own approver — the ordinary case, and one
     * that must not be recorded as a delegation.
     */
    private function delegationFor(ApprovalTask $task, User $actor): ?Delegation
    {
        if ($task->approver_id === $actor->id) {
            return null;
        }

        /*
         * The task was assigned to somebody else, so the actor needs an active
         * delegation FROM that person to act. Someone with a delegation for a
         * different approver must not be able to decide this task, and neither must
         * an administrator acting out of convenience — hence the check is against
         * the task's own approver, not merely "has any delegation".
         */
        $delegation = Delegation::for((int) $task->approver_id);

        if (! $delegation || $delegation->delegate_id !== $actor->id) {
            throw new \RuntimeException(
                'You are not the approver for this request, and no active delegation '
                .'authorises you to decide on their behalf.'
            );
        }

        return $delegation;
    }

    /**
     * Move the request according to the decision.
     *
     * The mapping differs by stage, which is why it is not on the Decision enum. An
     * approval by the Owner advances to the Sponsor; an approval at the committee is
     * the end of the decision chain. The same `Decision::Approved` means different
     * things depending on where it was made.
     */
    private function applyDecision(
        ItRequest $request,
        ApprovalTask $task,
        Decision $decision,
        ?string $comments,
    ): void {
        $stage = WorkflowStage::from($task->stage);

        match ($decision) {
            // The decision's own value is used as the action name, so the trail uses
            // one vocabulary rather than mixing `approved` with an internal `return`.
            Decision::Returned => $this->workflow->returnForAmendment($request, $comments ?? '', $decision->value),

            Decision::Rejected => $this->reject($request, $task, $comments),

            Decision::Approved, Decision::ApprovedWithConditions => $this->approve(
                $request,
                $stage,
                $decision,
            ),
        };
    }

    /**
     * Advance, or hand over to governance when the approval chain is complete.
     *
     * The Owner and Sponsor stages feed the governance stages; reaching the end of
     * the approvals is NOT the same as closing the request, which is a separate
     * governance act under BR-006.
     */
    private function approve(
        ItRequest $request,
        WorkflowStage $stage,
        Decision $decision,
    ): void {
        $next = $this->nextStageAfter($stage);

        if ($next === null) {
            /*
             * No further stage. The status is set from the decision rather than left
             * on the stage's status, because "Pending Committee Decision" would be
             * wrong once the committee has decided — and "Approved with conditions"
             * has its own status that the closure screen keys off.
             */
            $this->workflow->completeApprovals($request, $decision);

            return;
        }

        $this->workflow->advance($request, $next, $decision->value);
    }

    /**
     * What follows this stage.
     *
     * Only the two approval stages are handled here. Everything from completeness
     * review onward belongs to the governance module (Phase F), which decides whether
     * a request needs technical recommendations or a committee at all — so `null` here
     * means "the approval phase is over", not "the request is finished".
     */
    private function nextStageAfter(WorkflowStage $stage): ?WorkflowStage
    {
        return match ($stage) {
            /*
             * The Sponsor stage is entered only when a Sponsor is named.
             *
             * `WorkflowService::approverFor()` falls back to the Owner when no
             * Sponsor is named, and this must agree with that — otherwise a request
             * with no Sponsor is either parked at a Sponsor stage nobody holds, or
             * silently skips an approval that does exist. Both are worse than the
             * fallback, which at least records who decided.
             */
            WorkflowStage::ProjectOwner => WorkflowStage::ProjectSponsor,

            WorkflowStage::ProjectSponsor => WorkflowStage::CompletenessReview,

            default => null,
        };
    }

    /** Rejection ends the request with a terminal status (BR-002). */
    private function reject(ItRequest $request, ApprovalTask $task, ?string $comments): void
    {
        DB::transaction(function () use ($request, $task, $comments) {
            $request->forceFill([
                'status' => RequestStatus::NotRecommended->value,
                'outcome' => Decision::Rejected->closureOutcome(),
                'closed_at' => now(),
            ])->save();

            WorkflowHistory::create([
                'request_id' => $request->id,
                'from_stage' => $task->stage,
                'to_stage' => Decision::Rejected->value,
                'action' => 'reject',
                'performed_by' => auth()->id(),
                'remarks' => $comments,
            ]);
        });
    }

    /**
     * The submitter's own view of their decision rights.
     *
     * Used by the queue and the detail screen so the button, the menu and the policy
     * cannot disagree — the mismatch that made a Project Owner's page offer a button
     * their navigation hid.
     */
    public function canDecide(ItRequest $request, User $user): bool
    {
        $task = $request->pendingApprovalTask;

        return $task !== null
            && $this->authorisedDecider($task) === $user->id;
    }

    /**
     * Who may decide a task right now, accounting for delegation.
     *
     * Returns a user id. Kept in one place because the queue, the detail screen and
     * the policy all need the same answer, and three copies would drift — with the
     * drift showing up as a task that appears in somebody's queue but refuses their
     * decision, which reads as a broken application.
     */
    public function authorisedDecider(ApprovalTask $task): ?int
    {
        if ($task->decided_at !== null) {
            return null;
        }

        if ($task->approver_id !== null) {
            // The named approver, unless they are away and have delegated.
            $delegation = Delegation::for($task->approver_id);

            return $delegation?->delegate_id ?? $task->approver_id;
        }

        /*
         * Unassigned: resolved by role. Returns null when nobody holds the role,
         * which leaves the task genuinely unowned — the honest state, and one the
         * governance workspace shows as needing an administrator.
         */
        $role = match ($task->stage) {
            WorkflowStage::ProjectOwner->value => UserRole::ProjectOwner,
            WorkflowStage::ProjectSponsor->value => UserRole::ProjectSponsor,
            WorkflowStage::CompletenessReview->value => UserRole::GovernanceReviewer,
            WorkflowStage::TechnicalRecommendation->value => UserRole::TechnicalReviewer,
            WorkflowStage::Consolidation->value => UserRole::Hou,
            WorkflowStage::CommitteeDecision->value => UserRole::CommitteeSecretariat,
            default => null,
        };

        if ($role === null) {
            return null;
        }

        return User::query()
            ->active()
            ->whereHas('roles', fn ($q) => $q->where('name', $role->value))
            ->orderBy('id')
            ->value('id');
    }

    /** The configured target for a stage, exposed so the UI can explain a due date. */
    public function targetFor(WorkflowStage $stage, ?int $tierId = null): ?int
    {
        $model = WorkflowStageModel::where('code', $stage->value)->first();

        if (! $model) {
            return null;
        }

        $tier = $tierId ? Tier::find($tierId) : null;

        return $model->businessDaysFor($tier);
    }
}
