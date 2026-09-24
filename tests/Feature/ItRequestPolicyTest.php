<?php

use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Enums\WorkflowStage;
use App\Models\ApprovalTask;
use App\Models\ItRequest;
use App\Services\RequestNumberService;
use App\Services\WorkflowService;
use Database\Seeders\ReferenceDataSeeder;

/**
 * Who may see and do what to a request.
 *
 * The "must NOT" cases carry the weight here. A policy that grants too little is
 * an inconvenience somebody reports the same day; one that grants too much is a
 * disclosure that nobody notices, and the request body holds budgets, vendor
 * arrangements and internal justifications.
 */
beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);

    $this->workflow = app(WorkflowService::class);

    $this->requestor = asUser(UserRole::Requestor);
    $this->owner = asUser(UserRole::ProjectOwner);
    $this->sponsor = asUser(UserRole::ProjectSponsor);

    $this->request = ItRequest::create([
        'request_no' => app(RequestNumberService::class)->next(),
        'title' => 'Policy test request',
        'request_date' => now()->toDateString(),
        'requestor_id' => $this->requestor->id,
        'project_owner_id' => $this->owner->id,
        'project_sponsor_id' => $this->sponsor->id,
        'status' => RequestStatus::Draft->value,
        'current_stage' => WorkflowStage::Submission->value,
        'business_need' => 'Policy testing.',
    ]);
});

// ---- Visibility -------------------------------------------------------------

it('lets the requestor see their own request', function () {
    expect($this->requestor->can('view', $this->request))->toBeTrue();
});

it('lets the named owner and sponsor see the request', function () {
    expect($this->owner->can('view', $this->request))->toBeTrue()
        ->and($this->sponsor->can('view', $this->request))->toBeTrue();
});

it('refuses an unrelated employee', function () {
    /*
     * THE DEFECT THIS POLICY EXISTS TO CLOSE.
     *
     * The route was behind `auth` only, so any signed-in user could open any
     * request by changing the id in the URL. This asserts that a plain colleague —
     * signed in, valid account, no relationship to the request — cannot.
     */
    $stranger = plainUser();

    expect($stranger->can('view', $this->request))->toBeFalse();
});

it('lets an administrator and an auditor see any request', function () {
    expect(asUser(UserRole::Administrator)->can('view', $this->request))->toBeTrue()
        ->and(asUser(UserRole::Auditor)->can('view', $this->request))->toBeTrue();
});

// ---- Editing ----------------------------------------------------------------

it('lets the requestor edit their own draft', function () {
    expect($this->requestor->can('update', $this->request))->toBeTrue();
});

it('refuses an edit by anyone who is not the requestor', function () {
    // The Owner is named on the request and still may not rewrite the requestor's case.
    expect($this->owner->can('update', $this->request))->toBeFalse();
});

it('refuses an edit once the request is in the approval chain', function () {
    /*
     * The reason matters: an approver reading the request must be deciding on the
     * text they were given. An edit mid-flight means the decision on record does
     * not correspond to the document it was made about.
     */
    $this->workflow->submit($this->request);
    $submitted = $this->request->fresh();

    expect($this->requestor->can('update', $submitted))->toBeFalse();
});

it('lets the requestor edit again once the request is returned', function () {
    $this->workflow->submit($this->request);
    $this->workflow->returnForAmendment($this->request->fresh(), 'Needs detail.');

    expect($this->requestor->can('update', $this->request->fresh()))->toBeTrue();
});

// ---- Deletion ---------------------------------------------------------------

it('refuses deletion for everyone including the administrator', function () {
    /*
     * Not an omission — NFR-006. A request that can be deleted cannot be audited,
     * because the trail would show approvals for a record nobody can produce.
     * Withdrawal is the supported way to take a request out of play.
     */
    expect($this->requestor->can('delete', $this->request))->toBeFalse()
        ->and(asUser(UserRole::Administrator)->can('delete', $this->request))->toBeFalse();
});

// ---- Deciding ---------------------------------------------------------------

it('lets the named approver decide the outstanding task', function () {
    $this->workflow->submit($this->request);

    expect($this->owner->can('decide', $this->request->fresh()))->toBeTrue();
});

it('refuses a decision from somebody with no task', function () {
    $this->workflow->submit($this->request);

    // A plain colleague, and the Sponsor, whose turn has not come.
    expect(plainUser()->can('decide', $this->request->fresh()))->toBeFalse()
        ->and($this->sponsor->can('decide', $this->request->fresh()))->toBeFalse();
});

it('refuses a decision when no task is pending', function () {
    // Draft: nothing is awaiting anybody, so no ability to decide exists.
    expect($this->owner->can('decide', $this->request))->toBeFalse();
});

it('refuses a decision from an auditor', function () {
    /*
     * The Auditor must be able to read everything and change nothing. A read-only
     * role that can approve is not read-only, and it would put the auditor's own
     * name into the trail they are auditing.
     */
    $this->workflow->submit($this->request);
    $auditor = asUser(UserRole::Auditor);

    expect($auditor->can('decide', $this->request->fresh()))->toBeFalse();
});

it('routes an unassigned task to the holder of the stage role', function () {
    /*
     * Governance and technical stages are held by ROLE, so a task can legitimately
     * arrive unassigned. The role holder must still be able to pick it up — the
     * alternative is a request that nobody can move.
     */
    $this->workflow->submit($this->request);

    $request = $this->request->fresh();
    $request->forceFill([
        'current_stage' => WorkflowStage::CompletenessReview->value,
        'status' => RequestStatus::PendingCompletenessReview->value,
    ])->save();

    ApprovalTask::where('request_id', $request->id)->delete();
    ApprovalTask::create([
        'request_id' => $request->id,
        'stage' => WorkflowStage::CompletenessReview->value,
        'sequence' => 1,
        'approver_id' => null,   // nobody holds the role at submission time
    ]);

    $reviewer = asUser(UserRole::GovernanceReviewer);
    $technical = asUser(UserRole::TechnicalReviewer);

    expect($reviewer->can('decide', $request->fresh()))->toBeTrue()
        // The wrong role must not be able to claim it.
        ->and($technical->can('decide', $request->fresh()))->toBeFalse();
});

// ---- Submit and withdraw ----------------------------------------------------

it('refuses submission by anyone but the requestor', function () {
    expect($this->owner->can('submit', $this->request))->toBeFalse()
        ->and($this->requestor->can('submit', $this->request))->toBeTrue();
});

it('refuses a second submission once the request is already submitted', function () {
    /*
     * Found by walking the wizard in a browser, not by a test.
     *
     * Every test submitted a fresh request, so none of them tried the thing a real
     * user does within seconds of submitting: press the button again. The second
     * submission created a second approval task and a second history row, so the
     * owner saw the same request twice and the trail recorded two submissions.
     */
    $this->workflow->submit($this->request);
    $submitted = $this->request->fresh();

    expect($submitted->status)->toBe(RequestStatus::Submitted->value)
        ->and($this->requestor->can('submit', $submitted))->toBeFalse();
});

it('refuses withdrawal of a closed request', function () {
    $this->workflow->submit($this->request);
    ApprovalTask::where('request_id', $this->request->id)->pending()->update(['decided_at' => now()]);
    $this->workflow->close($this->request->fresh());

    $closed = $this->request->fresh();

    expect($closed->status)->toBe(RequestStatus::Closed->value)
        ->and($this->requestor->can('withdraw', $closed))->toBeFalse();
});
