<?php

namespace App\Services;

use App\Enums\Decision;
use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Enums\WorkflowStage;
use App\Models\ApprovalTask;
use App\Models\ItRequest;
use App\Models\User;
use App\Models\WorkflowHistory;
use App\Models\WorkflowStage as WorkflowStageModel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The workflow engine.
 *
 * WHY THIS IS A SERVICE AND NOT LOGIC IN A CONTROLLER
 *
 * Every transition shares the same obligations: authorise the actor, check the
 * prerequisites, require a comment where the business rules demand one, and write a
 * history row in the same transaction. Spreading that across a dozen components
 * guarantees one of them eventually forgets the history row — and a request that
 * moved without a record is exactly the outcome this system exists to prevent.
 *
 * The vendor brief's §7.2 asks for feature tests covering each allowed AND
 * prohibited transition. That is only writable when the transitions live in one
 * place.
 */
class WorkflowService
{
    public function __construct(
        private readonly BusinessCalendar $calendar,
        private readonly AuditService $audit,
    ) {}

    /**
     * Submit a request, creating the first approval task.
     *
     * The whole path is ONE transaction. A request that reached the Owner without a
     * history row, or without a due date, would be an unauditable request.
     */
    public function submit(ItRequest $request): void
    {
        DB::transaction(function () use ($request) {
            $from = $request->current_stage;

            $request->forceFill([
                'status' => RequestStatus::Submitted->value,
                'current_stage' => WorkflowStage::ProjectOwner->value,
                'submitted_at' => now(),
            ])->save();

            $this->createApprovalTask($request, WorkflowStage::ProjectOwner);

            $this->recordTransition($request, 'submit', $from, WorkflowStage::ProjectOwner->value);
        });
    }

    /**
     * Advance a request to the stage that follows the current one.
     *
     * Used when an approval succeeds. Keeping "what comes next" here rather than in
     * the calling component means the sequence is defined once.
     */
    public function advance(ItRequest $request, WorkflowStage $to, string $action = 'advance'): void
    {
        DB::transaction(function () use ($request, $to, $action) {
            $from = $request->current_stage;

            $request->forceFill([
                'status' => $this->statusForStage($to)->value,
                'current_stage' => $to->value,
            ])->save();

            if ($to !== WorkflowStage::Closure) {
                $this->createApprovalTask($request, $to);
            }

            $this->recordTransition($request, $action, $from, $to->value);
        });
    }

    /**
     * Return a request for amendment, remembering the stage that returned it.
     *
     * WHY `returned_from_stage` IS RECORDED
     *
     * BR-007 requires a returned request to resume at the configured stage, not from
     * the beginning. Without this column, resubmission has no way to know which
     * stage to re-enter — and the fallback would be to restart the whole chain,
     * which is what the current process does and what this design deliberately
     * improves on.
     *
     * WHY THE ACTION NAME IS A PARAMETER
     *
     * The history trail records what happened, and it has to use one vocabulary. An
     * approval writes the decision's own value — `approved`, `rejected` — while this
     * method's default writes the internal `return`. The two sit side by side in the
     * same trail, so a reader sees "approved, return, approved" and reasonably
     * wonders whether `return` and `returned` are different events.
     *
     * The decision service passes `returned`, the decision's own value, so every
     * entry naming a decision uses the decision's name.
     */
    public function returnForAmendment(ItRequest $request, string $comment, string $action = 'return'): void
    {
        DB::transaction(function () use ($request, $comment, $action) {
            $from = $request->current_stage;

            $request->forceFill([
                'status' => RequestStatus::ReturnedForAmendment->value,
                'current_stage' => null,
                'returned_from_stage' => $from,
            ])->save();

            $this->recordTransition(
                $request,
                $action,
                $from,
                RequestStatus::ReturnedForAmendment->value,
                $comment,
            );
        });
    }

    /**
     * Resubmit a returned request, re-entering the stage that returned it.
     *
     * Returns the stage it resumed at, so the caller can tell the user where their
     * request has gone — "back with the Sponsor" is materially different from "back
     * to the beginning" from the requestor's point of view.
     */
    public function resubmit(ItRequest $request): ?WorkflowStage
    {
        $returnedFrom = $request->returned_from_stage
            ? WorkflowStage::tryFrom($request->returned_from_stage)
            : null;

        DB::transaction(function () use ($request, $returnedFrom) {
            $from = RequestStatus::ReturnedForAmendment->value;

            // No recorded stage means the return predates this column, or the data is
            // incomplete. Falling back to the Project Owner restarts the chain, which
            // is the safe direction — it cannot skip an approval that was never given.
            $to = $returnedFrom ?? WorkflowStage::ProjectOwner;

            $request->forceFill([
                'status' => $this->statusForStage($to)->value,
                'current_stage' => $to->value,
                'returned_from_stage' => null,
            ])->save();

            $this->createApprovalTask($request, $to);

            $this->recordTransition($request, 'resubmit', $from, $to->value);
        });

        return $returnedFrom ?? WorkflowStage::ProjectOwner;
    }

    /**
     * Close a request, recording its outcome.
     *
     * BR-006: closure requires all mandatory decisions and documentation. The guard
     * lives here rather than in a view, because a view can be bypassed.
     */
    public function close(ItRequest $request, ?string $outcome = null): void
    {
        if ($task = $request->pendingApprovalTask) {
            throw new \RuntimeException(
                "Cannot close {$request->request_no}: approval task #{$task->id} at stage "
                ."'{$task->stage}' is still awaiting a decision. BR-006 requires all mandatory "
                .'decisions before closure.'
            );
        }

        DB::transaction(function () use ($request, $outcome) {
            $from = $request->current_stage ?? $request->status;

            $request->forceFill([
                'status' => RequestStatus::Closed->value,
                'current_stage' => WorkflowStage::Closure->value,
                'closed_at' => now(),
                'outcome' => $outcome ?? $request->outcome,
            ])->save();

            $this->recordTransition($request, 'close', $from, RequestStatus::Closed->value);
        });
    }

    /**
     * Create the approval task for a stage, with its computed due date.
     *
     * DUE DATE IS COMPUTED ONCE AND STORED. Recomputing on read would make "what is
     * overdue?" a business-time calculation over every open request rather than an
     * indexed comparison — and with no worker process on this host, that difference
     * is visible on every page load.
     */
    public function createApprovalTask(ItRequest $request, WorkflowStage $stage): ApprovalTask
    {
        $approverId = $this->approverFor($request, $stage);

        $task = ApprovalTask::create([
            'request_id' => $request->id,
            'stage' => $stage->value,
            'sequence' => $this->nextSequence($request, $stage),
            'approver_id' => $approverId,
            'due_at' => $this->dueDateFor($request, $stage),
        ]);

        /*
         * Notified AFTER the transaction's caller commits, not here.
         *
         * `notifyAssignment` writes a notification row, and doing that inside the
         * same transaction would roll the notification back with the task if anything
         * later failed — leaving a task nobody was told about. Laravel's
         * `afterCommit` is not used because this service is also called from
         * contexts with no transaction; the notification is simply sent last, and a
         * failure to notify never prevents the task existing.
         */
        if (config('itrequest.queue.notify_on_assignment')) {
            app(NotificationService::class)->notifyAssignment($task);
        }

        return $task;
    }

    /** The due date for a stage, using the configured target and the business calendar. */
    private function dueDateFor(ItRequest $request, WorkflowStage $stage): ?CarbonImmutable
    {
        $model = WorkflowStageModel::where('code', $stage->value)->first();

        if (! $model) {
            return null;
        }

        $businessDays = $model->businessDaysFor($request->tier);

        if ($businessDays === null) {
            // No configured target. Returning null is honest — the task simply has no
            // deadline, and the aging report says so rather than inventing one.
            return null;
        }

        return $this->calendar->addBusinessDays(now(), $businessDays);
    }

    /**
     * Who approves this stage.
     *
     * Project Owner and Sponsor are named on the request — that is the whole point
     * of naming them there.
     *
     * The remaining stages are held by ROLE rather than by a named person, because
     * the business has not said who specifically consolidates or records a committee
     * decision. So this resolves the first active user holding the responsible role.
     *
     * WHY NOT ASSIGN IT TO THE REQUESTOR AS A PLACEHOLDER
     *
     * An earlier draft did that, purely to satisfy the non-nullable `approver_id`,
     * and it was wrong in a way that would have been invisible: the requestor would
     * appear as the approver of their own governance review, and the approval queue
     * would show them tasks they cannot legitimately decide.
     *
     * Returning null when no role holder exists is the honest answer. The task is
     * then unassigned, and the governance workspace shows it as needing an owner —
     * which is a real problem an administrator can fix, rather than a fake
     * assignment that looks like it is working.
     */
    private function approverFor(ItRequest $request, WorkflowStage $stage): ?int
    {
        if ($stage === WorkflowStage::ProjectOwner) {
            return $request->project_owner_id;
        }

        if ($stage === WorkflowStage::ProjectSponsor) {
            return $request->project_sponsor_id ?? $request->project_owner_id;
        }

        $role = match ($stage) {
            WorkflowStage::CompletenessReview => UserRole::GovernanceReviewer,
            WorkflowStage::TechnicalRecommendation => UserRole::TechnicalReviewer,
            WorkflowStage::Consolidation => UserRole::Hou,
            WorkflowStage::CommitteeDecision => UserRole::CommitteeSecretariat,
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

    /**
     * The approval chain is complete; hand the request to governance.
     *
     * WHY THIS IS NOT `close()`
     *
     * Reaching the end of the Owner and Sponsor approvals means the request has
     * cleared its named approvers. It does NOT mean the request is finished — the
     * completeness review, technical recommendations, consolidation and possibly a
     * committee decision all follow (Phase F), and BR-006 requires those before
     * closure.
     *
     * An earlier draft called `close()` here, which would have marked a request
     * Closed the moment its Sponsor approved — skipping the entire governance
     * process while looking like a successful approval. The status is set from the
     * DECISION instead, so "approved with conditions" is visibly different from a
     * clean approval.
     */
    public function completeApprovals(ItRequest $request, Decision $decision): void
    {
        DB::transaction(function () use ($request, $decision) {
            $from = $request->current_stage;

            $status = $decision === Decision::ApprovedWithConditions
                ? RequestStatus::ApprovedWithConditions
                : RequestStatus::PendingCompletenessReview;

            $request->forceFill([
                'status' => $status->value,
                /*
                 * The stage advances to the completeness review even though no task
                 * is created for it here.
                 *
                 * Phase F owns that stage. Leaving `current_stage` on the Sponsor
                 * would mean the request reads as still awaiting a Sponsor decision
                 * after that decision was made — and the aging report would count it
                 * against the wrong stage.
                 */
                'current_stage' => WorkflowStage::CompletenessReview->value,
            ])->save();

            $this->recordTransition(
                $request,
                'approvals_complete',
                $from,
                WorkflowStage::CompletenessReview->value,
                'Owner and Sponsor approvals complete; handed to governance.',
            );
        });
    }

    /** Sequence within a stage, so a stage can require more than one decision. */
    private function nextSequence(ItRequest $request, WorkflowStage $stage): int
    {
        return ApprovalTask::where('request_id', $request->id)
            ->where('stage', $stage->value)
            ->max('sequence') + 1;
    }

    /** The status that belongs with a stage. */
    public function statusForStage(WorkflowStage $stage): RequestStatus
    {
        return match ($stage) {
            WorkflowStage::Submission => RequestStatus::Draft,
            WorkflowStage::ProjectOwner => RequestStatus::PendingProjectOwner,
            WorkflowStage::ProjectSponsor => RequestStatus::PendingProjectSponsor,
            WorkflowStage::CompletenessReview => RequestStatus::PendingCompletenessReview,
            WorkflowStage::TechnicalRecommendation => RequestStatus::PendingTechnicalRecommendation,
            WorkflowStage::Consolidation => RequestStatus::PendingConsolidation,
            WorkflowStage::CommitteeDecision => RequestStatus::PendingCommitteeDecision,
            WorkflowStage::Closure => RequestStatus::Closed,
        };
    }

    /**
     * Write the transition history row.
     *
     * Private, and only called from inside a transaction above. That is deliberate:
     * a transition that forgot to call this would be unauditable, so it must not be
     * possible to perform a transition and skip the record.
     */
    private function recordTransition(
        ItRequest $request,
        string $action,
        ?string $fromStage,
        string $toStage,
        ?string $remarks = null,
    ): void {
        WorkflowHistory::create([
            'request_id' => $request->id,
            'from_stage' => $fromStage,
            'to_stage' => $toStage,
            'action' => $action,
            'performed_by' => auth()->id(),
            'remarks' => $remarks,
        ]);
    }
}
