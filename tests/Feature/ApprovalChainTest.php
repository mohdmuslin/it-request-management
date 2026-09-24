<?php

use App\Enums\Decision;
use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Enums\WorkflowStage;
use App\Models\ApprovalTask;
use App\Models\Delegation;
use App\Models\Department;
use App\Models\ItRequest;
use App\Models\WorkflowHistory;
use App\Services\RequestNumberService;
use App\Services\WorkflowDecisionService;
use App\Services\WorkflowService;
use Database\Seeders\ReferenceDataSeeder;

/**
 * The approval chain.
 *
 * Phase E's exit criteria are "a request clears Owner then Sponsor" and "a return
 * goes back only to the returning stage and resumes correctly" — and the plan
 * requires the tests to prove PROHIBITED transitions are refused, not only that
 * the allowed ones work.
 */
beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);

    $this->workflow = app(WorkflowService::class);
    $this->decisions = app(WorkflowDecisionService::class);

    $this->requestor = asUser(UserRole::Requestor);
    $this->owner = asUser(UserRole::ProjectOwner);
    $this->sponsor = asUser(UserRole::ProjectSponsor);

    $this->department = Department::create(['code' => 'ICT', 'name' => 'Information Technology']);

    $this->request = ItRequest::create([
        'request_no' => app(RequestNumberService::class)->next(),
        'title' => 'Replacement of the ageing file server',
        'request_date' => now()->toDateString(),
        'requestor_id' => $this->requestor->id,
        'department_id' => $this->department->id,
        'project_owner_id' => $this->owner->id,
        'project_sponsor_id' => $this->sponsor->id,
        'status' => RequestStatus::Draft->value,
        'current_stage' => WorkflowStage::Submission->value,
        'business_need' => 'The file server is out of warranty and has failed twice.',
    ]);
});

/** Sign in as the user and decide the request's current stage. */
function decide($decision, ?string $comments = null, array $conditions = [])
{
    return app(WorkflowDecisionService::class)->decide(
        request: test()->request->fresh(),
        actor: auth()->user(),
        decision: $decision,
        comments: $comments,
        conditions: $conditions,
    );
}

// ---- The chain --------------------------------------------------------------

it('clears the owner then the sponsor', function () {
    $this->workflow->submit($this->request);
    $this->request->refresh();

    expect($this->request->current_stage)->toBe(WorkflowStage::ProjectOwner->value);

    // Owner approves.
    $this->actingAs($this->owner);
    decide(Decision::Approved);
    $this->request->refresh();

    expect($this->request->current_stage)->toBe(WorkflowStage::ProjectSponsor->value)
        ->and($this->request->status)->toBe(RequestStatus::PendingProjectSponsor->value);

    // Sponsor approves.
    $this->actingAs($this->sponsor);
    decide(Decision::Approved);
    $this->request->refresh();

    // Handed to governance, NOT closed — the completeness review and everything
    // after it is Phase F. Treating this as closure would skip the whole governance
    // process while looking like a successful approval.
    expect($this->request->current_stage)->toBe(WorkflowStage::CompletenessReview->value)
        ->and($this->request->status)->toBe(RequestStatus::PendingCompletenessReview->value)
        ->and($this->request->closed_at)->toBeNull();
});

it('creates exactly one task per stage, not one per approver', function () {
    // Two pending tasks would let two people decide the same stage, and the second
    // decision would silently overwrite the first.
    $this->workflow->submit($this->request);

    $this->actingAs($this->owner);
    decide(Decision::Approved);

    $pending = ApprovalTask::where('request_id', $this->request->id)->pending()->get();

    expect($pending)->toHaveCount(1)
        ->and($pending->first()->stage)->toBe(WorkflowStage::ProjectSponsor->value);
});

it('assigns each stage to the person named on the request', function () {
    $this->workflow->submit($this->request);
    $this->actingAs($this->owner);
    decide(Decision::Approved);

    $sponsorTask = ApprovalTask::where('request_id', $this->request->id)->pending()->first();

    expect($sponsorTask->approver_id)->toBe($this->sponsor->id);
});

// ---- Returns (BR-007, UAT-006, UAT-007) -------------------------------------

it('returns the request to the requestor and resumes at the returning stage', function () {
    /*
     * THE REQUIREMENT THAT DEPARTS FROM THE CURRENT PROCESS.
     *
     * The flow diagram loops a return back to "SUBMIT FOR APPROVAL", which restarts
     * both approvals. BR-007 requires resuming at the configured stage instead — so
     * a Sponsor's return goes back to the Sponsor, not to the Owner, whose decision
     * was never the problem.
     */
    $this->workflow->submit($this->request);

    $this->actingAs($this->owner);
    decide(Decision::Approved);

    $this->actingAs($this->sponsor);
    decide(Decision::Returned, 'The budget needs itemising before I can approve this.');
    $this->request->refresh();

    expect($this->request->status)->toBe(RequestStatus::ReturnedForAmendment->value)
        ->and($this->request->returned_from_stage)->toBe(WorkflowStage::ProjectSponsor->value)
        // Current stage cleared: nobody holds it while the requestor works.
        ->and($this->request->current_stage)->toBeNull();

    // Resubmission resumes at the Sponsor, not the Owner.
    $resumed = $this->workflow->resubmit($this->request->fresh());

    expect($resumed)->toBe(WorkflowStage::ProjectSponsor);
});

it('refuses a return without a comment', function () {
    /*
     * BR-002, and a PROHIBITED transition.
     *
     * A return with no reason is unactionable by definition — the requestor has been
     * told to fix something and not told what.
     */
    $this->workflow->submit($this->request);
    $this->actingAs($this->owner);

    expect(fn () => decide(Decision::Returned, null))
        ->toThrow(RuntimeException::class, 'A comment is required');
});

it('refuses a rejection without a comment', function () {
    // The comment is the only record of why the request ended.
    $this->workflow->submit($this->request);
    $this->actingAs($this->owner);

    expect(fn () => decide(Decision::Rejected, ''))
        ->toThrow(RuntimeException::class, 'A comment is required');
});

it('refuses a rejection with only whitespace as a comment', function () {
    // `blank()` rather than an empty-string check: a space is not a reason.
    $this->workflow->submit($this->request);
    $this->actingAs($this->owner);

    expect(fn () => decide(Decision::Rejected, '   '))
        ->toThrow(RuntimeException::class, 'A comment is required');
});

it('refuses an approval with conditions that records no conditions', function () {
    // The condition would exist only in somebody's memory.
    $this->workflow->submit($this->request);
    $this->actingAs($this->owner);

    expect(fn () => decide(Decision::ApprovedWithConditions, 'Fine', []))
        ->toThrow(RuntimeException::class, 'requires the conditions');
});

it('records the conditions on the task', function () {
    $this->workflow->submit($this->request);
    $this->actingAs($this->owner);

    $task = decide(Decision::ApprovedWithConditions, 'Approved subject to the following.', [
        'Vendor must provide a 3-year support commitment.',
    ]);

    expect($task->conditions)->toContain('3-year support commitment');
});

it('still advances the request when the owner approves with conditions', function () {
    /*
     * A conditional approval is an approval.
     *
     * The first version of this test asserted the request status became
     * `approved_with_conditions` — and the code returned `pending_project_sponsor`,
     * which is the correct answer. The Owner approving does not finish the chain;
     * the Sponsor still has to decide, and "pending project sponsor" is an accurate
     * description of a request waiting on the Sponsor.
     *
     * The conditions are not lost: they are stored on the TASK where the decision
     * was made, which is what the approval trail shows and what the closure screen
     * cites. A request-level status cannot carry a per-stage condition, and trying
     * to make it would mean the status said "approved with conditions" while the
     * request was still awaiting an approval.
     */
    $this->workflow->submit($this->request);
    $this->actingAs($this->owner);

    decide(Decision::ApprovedWithConditions, 'Approved subject to the following.', [
        'Vendor must provide a 3-year support commitment.',
    ]);
    $this->request->refresh();

    expect($this->request->status)->toBe(RequestStatus::PendingProjectSponsor->value)
        ->and($this->request->current_stage)->toBe(WorkflowStage::ProjectSponsor->value);
});

// ---- Rejection --------------------------------------------------------------

it('ends the request on rejection', function () {
    $this->workflow->submit($this->request);
    $this->actingAs($this->owner);

    decide(Decision::Rejected, 'The business case does not justify the cost at this time.');
    $this->request->refresh();

    expect($this->request->status)->toBe(RequestStatus::NotRecommended->value)
        ->and($this->request->outcome)->toBe('not_recommended')
        ->and($this->request->closed_at)->not->toBeNull();
});

// ---- Prohibited decisions ---------------------------------------------------

it('refuses a decision from somebody who is not the approver', function () {
    /*
     * The important half. A policy that shows the wrong queue is a usability bug; a
     * service that ACCEPTS the wrong decision is an authority bypass.
     */
    $this->workflow->submit($this->request);

    // The Sponsor's turn has not come.
    $this->actingAs($this->sponsor);

    expect(fn () => decide(Decision::Approved))
        ->toThrow(RuntimeException::class, 'no active delegation');
});

it('refuses a second decision on the same stage', function () {
    /*
     * Reachable by leaving the page open past somebody else's decision. Recording
     * the second would overwrite a decision already made, and the trail would show
     * one decision where two people acted.
     */
    $this->workflow->submit($this->request);
    $this->actingAs($this->owner);
    decide(Decision::Approved);

    // Now at the Sponsor stage, so the Owner deciding again finds no task for them.
    $this->actingAs($this->owner);

    expect(fn () => decide(Decision::Approved))
        ->toThrow(RuntimeException::class);
});

it('refuses a decision on a request that was never submitted', function () {
    $this->actingAs($this->owner);

    expect(fn () => decide(Decision::Approved))
        ->toThrow(RuntimeException::class, 'no decision awaiting');
});

// ---- Delegation (FR-014, BR-008) --------------------------------------------

it('lets a delegate decide on the approver\'s behalf', function () {
    /*
     * THE POINT OF THE FEATURE. Without it, an approver on leave stops every request
     * waiting on them — the single point of failure in the current process.
     */
    $delegate = asUser(UserRole::ProjectOwner);

    $this->workflow->submit($this->request);

    Delegation::create([
        'approver_id' => $this->owner->id,
        'delegate_id' => $delegate->id,
        'created_by' => $this->owner->id,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addWeek(),
        'reason' => 'Annual leave',
    ]);

    $this->actingAs($delegate);
    $task = decide(Decision::Approved);
    $this->request->refresh();

    // The request moved.
    expect($this->request->current_stage)->toBe(WorkflowStage::ProjectSponsor->value);

    // BR-008: BOTH users recorded. Recording only the substitute loses who the
    // decision belonged to; recording only the original loses who clicked.
    expect($task->approver_id)->toBe($delegate->id)
        ->and($task->delegated_from_id)->toBe($this->owner->id);
});

it('refuses a delegate whose delegation has not started', function () {
    $delegate = asUser(UserRole::ProjectOwner);

    $this->workflow->submit($this->request);

    Delegation::create([
        'approver_id' => $this->owner->id,
        'delegate_id' => $delegate->id,
        'created_by' => $this->owner->id,
        'starts_at' => now()->addWeek(),
        'ends_at' => now()->addWeeks(2),
    ]);

    $this->actingAs($delegate);

    // A future arrangement must not grant authority early.
    expect(fn () => decide(Decision::Approved))
        ->toThrow(RuntimeException::class, 'no active delegation');
});

it('refuses a delegate whose delegation has been revoked', function () {
    $delegate = asUser(UserRole::ProjectOwner);

    $this->workflow->submit($this->request);

    Delegation::create([
        'approver_id' => $this->owner->id,
        'delegate_id' => $delegate->id,
        'created_by' => $this->owner->id,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addWeek(),
        'revoked_at' => now(),
    ]);

    $this->actingAs($delegate);

    expect(fn () => decide(Decision::Approved))
        ->toThrow(RuntimeException::class, 'no active delegation');
});

it('refuses a delegation that authorises a different approver', function () {
    /*
     * Having "a delegation" is not enough. It must be for THIS task's approver,
     * otherwise anybody with cover for any approver could decide every task.
     */
    $otherApprover = asUser(UserRole::ProjectOwner);
    $delegate = asUser(UserRole::ProjectOwner);

    $this->workflow->submit($this->request);

    Delegation::create([
        'approver_id' => $otherApprover->id,
        'delegate_id' => $delegate->id,
        'created_by' => $otherApprover->id,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addWeek(),
    ]);

    $this->actingAs($delegate);

    expect(fn () => decide(Decision::Approved))
        ->toThrow(RuntimeException::class, 'no active delegation');
});

it('does not record the approver as a delegation when they act themselves', function () {
    // The ordinary case must not look like a delegated decision.
    $this->workflow->submit($this->request);
    $this->actingAs($this->owner);

    $task = decide(Decision::Approved);

    expect($task->approver_id)->toBe($this->owner->id)
        ->and($task->delegated_from_id)->toBeNull();
});

it('names the delegate as the person who can decide, not the absent approver', function () {
    // What the queue asks. Getting this wrong shows the task to somebody who cannot
    // act on it, and hides it from the person who can.
    $delegate = asUser(UserRole::ProjectOwner);

    $this->workflow->submit($this->request);

    Delegation::create([
        'approver_id' => $this->owner->id,
        'delegate_id' => $delegate->id,
        'created_by' => $this->owner->id,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addWeek(),
    ]);

    $task = ApprovalTask::where('request_id', $this->request->id)->pending()->first();

    expect($this->decisions->authorisedDecider($task))->toBe($delegate->id);
});

// ---- Notifications ----------------------------------------------------------

it('records a notification for the approver when a task is assigned', function () {
    /*
     * Recorded, not necessarily delivered: the Phase C email gate is unproven, so
     * `mail_enabled` is false and rows stay `pending`. That is deliberate — marking
     * an undelivered notification `sent` would make the log assert a delivery that
     * never happened.
     */
    $this->workflow->submit($this->request);

    $this->assertDatabaseHas('notifications', [
        'user_id' => $this->owner->id,
        'template' => 'approval.assigned',
        'status' => 'pending',
    ]);
});

it('records a notification to the requestor when a decision is made', function () {
    $this->workflow->submit($this->request);
    $this->actingAs($this->owner);

    decide(Decision::Approved);

    $this->assertDatabaseHas('notifications', [
        'user_id' => $this->requestor->id,
        'template' => 'decision.recorded',
    ]);
});

it('does not send a decision notification when the feature is off', function () {
    config()->set('itrequest.queue.notify_on_decision', false);

    $this->workflow->submit($this->request);
    $this->actingAs($this->owner);

    decide(Decision::Approved);

    $this->assertDatabaseMissing('notifications', ['template' => 'decision.recorded']);
});

it('records a notification rather than failing when mail is disabled', function () {
    /*
     * A decision must not fail because an email could not be sent. The decision is
     * the record; the email is a courtesy.
     */
    config()->set('itrequest.notifications.mail_enabled', false);

    $this->workflow->submit($this->request);
    $this->actingAs($this->owner);

    $task = decide(Decision::Approved);

    expect($task->decision)->toBe(Decision::Approved)
        ->and($this->request->fresh()->current_stage)->toBe(WorkflowStage::ProjectSponsor->value);
});

// ---- The trail --------------------------------------------------------------

it('records every decision in the history', function () {
    $this->workflow->submit($this->request);

    $this->actingAs($this->owner);
    decide(Decision::Approved);

    $this->actingAs($this->sponsor);
    decide(Decision::Returned, 'Needs more detail on the running costs.');

    $actions = WorkflowHistory::where('request_id', $this->request->id)->pluck('action')->all();

    expect($actions)->toBe(['submit', 'approved', 'returned']);
});

it('records who decided, not only that a decision happened', function () {
    $this->workflow->submit($this->request);
    $this->actingAs($this->owner);
    decide(Decision::Approved);

    $entry = WorkflowHistory::where('request_id', $this->request->id)
        ->where('action', 'approved')
        ->first();

    expect($entry->performed_by)->toBe($this->owner->id);
});
