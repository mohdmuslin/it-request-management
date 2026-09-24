<?php

namespace App\Policies;

use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Enums\WorkflowStage;
use App\Models\ItRequest;
use App\Models\User;

/**
 * Who may see and do what to an IT request.
 *
 * Laravel discovers this by convention (`App\Policies\ItRequestPolicy` for
 * `App\Models\ItRequest`), so there is nothing to register.
 *
 * WHY THIS EXISTS AT ALL
 *
 * The request route was originally behind `auth` only, which meant any signed-in
 * user could open any request by changing the id in the URL. Request data includes
 * budgets, vendor arrangements and internal justifications, and a requestor's
 * commercially sensitive case being readable by anyone with an account is a real
 * disclosure — not a theoretical one.
 *
 * The rule this encodes: you can see a request you raised, one you are named on,
 * one you have been asked to decide, or you hold a role whose job is to see it.
 * Access is derived from the record, never from knowing the id.
 */
class ItRequestPolicy
{
    /**
     * The list of requests.
     *
     * Everyone who can raise a request can see a list — it is scoped to their own
     * requests in the component. The Auditor sees everything, read-only.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ItRequest $request): bool
    {
        return $this->isAdministratorOrAuditor($user)
            || $this->isNamedOn($user, $request)
            || $this->isStakeholder($user);
    }

    /**
     * May this user raise a request.
     *
     * The Auditor is read-only by design (NFR-006 requires ordinary users to be
     * unable to alter critical records, and an auditor who can file requests can
     * also appear as a requestor in the trail they are auditing).
     */
    public function create(User $user): bool
    {
        return ! $user->isReadOnly();
    }

    /**
     * May this user edit the request body.
     *
     * Only the requestor, and only while the request is theirs to change: a draft,
     * or one returned to them for amendment. Once it is in an approval queue it is
     * not the requestor's document any more — editing it while an approver is
     * reading it would mean the approver decides on text that no longer exists.
     */
    public function update(User $user, ItRequest $request): bool
    {
        if ($user->isReadOnly()) {
            return false;
        }

        $editable = in_array($request->status, [
            RequestStatus::Draft->value,
            RequestStatus::ReturnedForAmendment->value,
        ], true);

        return $editable && ($this->isRequestor($user, $request) || $user->isAdministrator());
    }

    /**
     * Deletion is refused, always, for everyone.
     *
     * Not an omission. NFR-006 makes critical records immutable, and a request that
     * can be deleted cannot be audited — the trail would show approvals for
     * something nobody can produce. Withdrawal exists as the way to take a request
     * out of play, and the `delete` ability deliberately does not.
     */
    public function delete(User $user, ItRequest $request): bool
    {
        return false;
    }

    /**
     * The requestor may move their own request into the approval chain.
     *
     * THE STATUS CHECK IS NOT OPTIONAL.
     *
     * Without it, the requestor can submit the same request twice: the second
     * submission creates a second approval task and a second history row, so the
     * owner sees the same request awaiting them twice and the trail reports two
     * submissions that never happened. Found by walking the wizard in a browser —
     * every test passed, because each one submitted a fresh request.
     *
     * A returned request is submitted through `resubmit()` instead, which re-enters
     * the stage that returned it rather than starting the chain again (BR-007).
     */
    public function submit(User $user, ItRequest $request): bool
    {
        return ! $user->isReadOnly()
            && $this->isRequestor($user, $request)
            && $request->status === RequestStatus::Draft->value;
    }

    /** The requestor may withdraw their own request while it is still open. */
    public function withdraw(User $user, ItRequest $request): bool
    {
        if ($user->isReadOnly() || ! $this->isRequestor($user, $request)) {
            return false;
        }

        return ! in_array($request->status, [
            RequestStatus::Closed->value,
            RequestStatus::Withdrawn->value,
        ], true);
    }

    /** The requestor may resubmit once it has been returned to them. */
    public function resubmit(User $user, ItRequest $request): bool
    {
        return ! $user->isReadOnly()
            && $this->isRequestor($user, $request)
            && $request->status === RequestStatus::ReturnedForAmendment->value;
    }

    /**
     * May this user decide the outstanding task at the current stage.
     *
     * Two conditions, both necessary: the user holds a role that decides at this
     * stage, AND there is a pending task at that stage.
     *
     * The pending-task check is what stops a stale screen from approving. Without
     * it, an approver who left the page open could approve a stage the request has
     * already moved past — and the history would record an approval that was never
     * the current step.
     */
    public function decide(User $user, ItRequest $request): bool
    {
        if ($user->isReadOnly() || $user->isAdministrator()) {
            return false;
        }

        $task = $request->pendingApprovalTask;

        if (! $task) {
            return false;
        }

        // Assigned to this user specifically.
        if ($task->approver_id !== null && $task->approver_id === $user->id) {
            return true;
        }

        // Unassigned, but this user holds the role responsible for the stage.
        return $task->stage === $request->current_stage
            && $this->roleForStage($task->stage, $user);
    }

    /** Closure is a governance act, not an approver's. */
    public function close(User $user, ItRequest $request): bool
    {
        return $user->hasAnyRole(
            UserRole::GovernanceReviewer,
            UserRole::Hou,
            UserRole::CommitteeSecretariat,
            UserRole::Administrator,
        );
    }

    /**
     * May this user assess the request's completeness (tier and classification).
     *
     * The Governance Reviewer confirms or corrects what the requestor proposed. The
     * requestor cannot assess their own request — that would make the proposal the
     * decision, and there would be nothing to confirm.
     */
    public function assess(User $user, ItRequest $request): bool
    {
        return ! $user->isReadOnly()
            && $request->current_stage === WorkflowStage::CompletenessReview->value
            && $user->hasAnyRole(UserRole::GovernanceReviewer, UserRole::Administrator);
    }

    /**
     * May this user file a recommendation for a reviewing unit.
     *
     * Membership of an ASSIGNED unit is checked in the service, against the unit
     * being filed for. This only establishes that the user is a technical reviewer at
     * all, so the policy does not have to know which unit the form was submitted for.
     */
    public function recommend(User $user, ItRequest $request): bool
    {
        return ! $user->isReadOnly()
            && $request->current_stage === WorkflowStage::TechnicalRecommendation->value
            && $user->hasAnyRole(UserRole::TechnicalReviewer, UserRole::Administrator);
    }

    /** May this user consolidate and set the governance route. */
    public function consolidate(User $user, ItRequest $request): bool
    {
        return ! $user->isReadOnly()
            && $request->current_stage === WorkflowStage::Consolidation->value
            && $user->hasAnyRole(UserRole::Hou, UserRole::Administrator);
    }

    /**
     * May this user record the committee's decision.
     *
     * Requires the ROUTE to demand a committee, not only the stage — a decision by a
     * body with no authority over the request would otherwise look entirely
     * legitimate on the screen.
     */
    public function recordCommitteeDecision(User $user, ItRequest $request): bool
    {
        return ! $user->isReadOnly()
            && $request->current_stage === WorkflowStage::CommitteeDecision->value
            && (bool) $request->governanceRoute?->requires_committee
            && $user->hasAnyRole(UserRole::CommitteeSecretariat, UserRole::Administrator);
    }

    /** The requestor, the Owner or the Sponsor. */
    private function isNamedOn(User $user, ItRequest $request): bool
    {
        return $user->id === $request->requestor_id
            || $user->id === $request->project_owner_id
            || $user->id === $request->project_sponsor_id;
    }

    private function isRequestor(User $user, ItRequest $request): bool
    {
        return $user->id === $request->requestor_id;
    }

    /**
     * Holds a role whose job includes seeing requests.
     *
     * Deliberately NOT every signed-in user. "Everyone in the company works in IT"
     * is the assumption that produced the current problem.
     */
    private function isStakeholder(User $user): bool
    {
        return $user->hasAnyRole(
            UserRole::GovernanceReviewer,
            UserRole::TechnicalReviewer,
            UserRole::Hou,
            UserRole::CommitteeSecretariat,
        );
    }

    /** The Administrator and the Auditor see everything; neither may decide. */
    private function isAdministratorOrAuditor(User $user): bool
    {
        return $user->isAdministrator() || $user->isReadOnly();
    }

    /**
     * Does the user hold the role that decides at a given stage?
     *
     * This is the FALLBACK path, for a task with no named approver. The Owner and
     * Sponsor stages always have a named approver, so they reach this only when the
     * assignment failed — in which case requiring the role is still the right check,
     * because it at least confines the decision to somebody whose job it is.
     *
     * Returns false for an unrecognised stage rather than true. A stage this method
     * has not heard of is a stage whose approver rules are unknown, and guessing in
     * the permissive direction on an authorisation check is how a defect becomes a
     * disclosure.
     */
    private function roleForStage(string $stage, User $user): bool
    {
        $role = match ($stage) {
            'project_owner' => UserRole::ProjectOwner,
            'project_sponsor' => UserRole::ProjectSponsor,
            'completeness_review' => UserRole::GovernanceReviewer,
            'technical_recommendation' => UserRole::TechnicalReviewer,
            'consolidation' => UserRole::Hou,
            'committee_decision' => UserRole::CommitteeSecretariat,
            default => null,
        };

        return $role !== null && $user->hasRole($role);
    }
}
