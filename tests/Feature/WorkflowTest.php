<?php

use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Enums\WorkflowStage;
use App\Models\ApprovalTask;
use App\Models\ItRequest;
use App\Models\WorkflowHistory;
use App\Services\RequestNumberService;
use App\Services\WorkflowService;
use Database\Seeders\ReferenceDataSeeder;

/**
 * The workflow engine.
 *
 * The vendor brief's §7.2 asks for feature tests covering each ALLOWED and each
 * PROHIBITED transition. The "prohibited" half is the one that actually matters:
 * a transition that wrongly succeeds is a security defect, whereas one that
 * wrongly fails is an inconvenience somebody reports.
 */
beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);

    $this->workflow = app(WorkflowService::class);
    $this->numbers = app(RequestNumberService::class);

    $this->requestor = asUser(UserRole::Requestor);
    $this->owner = asUser(UserRole::ProjectOwner);
    $this->sponsor = asUser(UserRole::ProjectSponsor);
});

/** A minimal draft request, owned by the requestor. */
function draftRequest($requestor, $owner, ?string $number = null): ItRequest
{
    return ItRequest::create([
        'request_no' => $number ?? app(RequestNumberService::class)->next(),
        'title' => 'Test request',
        'request_date' => now()->toDateString(),
        'requestor_id' => $requestor->id,
        'project_owner_id' => $owner->id,
        'status' => RequestStatus::Draft->value,
        'current_stage' => WorkflowStage::Submission->value,
        'business_need' => 'Testing.',
    ]);
}

// ---- Request numbers (FR-005, UAT-003) -------------------------------------

it('generates a unique request number for each request', function () {
    $first = $this->numbers->next();
    draftRequest($this->requestor, $this->owner, $first);

    $second = $this->numbers->next();

    expect($second)->not->toBe($first);
});

it('generates numbers matching the configured pattern', function () {
    $number = $this->numbers->next();
    $year = now(config('itrequest.business_hours.timezone'))->year;

    // REQ-{year}-{seq} with four-digit padding.
    expect($number)->toMatch('/^REQ-'.$year.'-\d{4}$/');
});

it('continues the sequence rather than restarting', function () {
    // Seed an existing number, so the next one must follow it.
    draftRequest($this->requestor, $this->owner, 'REQ-2026-0007');

    expect($this->numbers->next())->toEndWith('0008');
});

// ---- Submission (UAT-001, UAT-002) -----------------------------------------

it('moves a draft to pending owner on submission', function () {
    $request = draftRequest($this->requestor, $this->owner);

    $this->workflow->submit($request);
    $request->refresh();

    expect($request->status)->toBe(RequestStatus::Submitted->value)
        ->and($request->current_stage)->toBe(WorkflowStage::ProjectOwner->value)
        ->and($request->submitted_at)->not->toBeNull();
});

it('creates an approval task with a due date on submission', function () {
    $request = draftRequest($this->requestor, $this->owner);

    $this->workflow->submit($request);

    $task = $request->fresh()->pendingApprovalTask;

    expect($task)->not->toBeNull()
        ->and($task->stage)->toBe(WorkflowStage::ProjectOwner->value)
        ->and($task->approver_id)->toBe($this->owner->id)
        // A null due date would mean no reminder and no escalation can ever fire.
        ->and($task->due_at)->not->toBeNull();
});

it('writes a history row for the submission', function () {
    $request = draftRequest($this->requestor, $this->owner);

    $this->workflow->submit($request);

    $history = WorkflowHistory::where('request_id', $request->id)->get();

    expect($history)->toHaveCount(1)
        ->and($history->first()->action)->toBe('submit')
        ->and($history->first()->to_stage)->toBe(WorkflowStage::ProjectOwner->value);
});

it('advances through the approval chain in order', function () {
    $request = draftRequest($this->requestor, $this->owner);

    $this->workflow->submit($request);
    $this->workflow->advance($request->fresh(), WorkflowStage::ProjectSponsor, 'approve');
    $request->refresh();

    expect($request->status)->toBe(RequestStatus::PendingProjectSponsor->value)
        ->and($request->current_stage)->toBe(WorkflowStage::ProjectSponsor->value);

    // Two history rows: submit, then approve.
    expect(WorkflowHistory::where('request_id', $request->id)->count())->toBe(2);
});

// ---- Returns (BR-007, UAT-006, UAT-007) ------------------------------------

it('records the returning stage when a request is returned', function () {
    $request = draftRequest($this->requestor, $this->owner);
    $this->workflow->submit($request);

    // Sponsor is the stage that returns it.
    $this->workflow->advance($request->fresh(), WorkflowStage::ProjectSponsor, 'approve');
    $this->workflow->returnForAmendment($request->fresh(), 'Budget needs itemising.');
    $request->refresh();

    expect($request->status)->toBe(RequestStatus::ReturnedForAmendment->value)
        ->and($request->returned_from_stage)->toBe(WorkflowStage::ProjectSponsor->value)
        // The current stage is cleared: nobody is holding it while the requestor works.
        ->and($request->current_stage)->toBeNull();
});

it('resumes at the returning stage rather than restarting the chain', function () {
    /*
     * THE BEHAVIOUR THAT DEPARTS FROM THE CURRENT PROCESS.
     *
     * The flow diagram loops a return back to "SUBMIT FOR APPROVAL", which restarts
     * both approvals. BR-007 requires resuming at the configured stage instead, so a
     * Sponsor's return goes back to the Sponsor — not to the Owner, whose decision
     * was never the problem.
     */
    $request = draftRequest($this->requestor, $this->owner);
    $this->workflow->submit($request);
    $this->workflow->advance($request->fresh(), WorkflowStage::ProjectSponsor, 'approve');
    $this->workflow->returnForAmendment($request->fresh(), 'Needs more detail.');

    $resumed = $this->workflow->resubmit($request->fresh());
    $request->refresh();

    expect($resumed)->toBe(WorkflowStage::ProjectSponsor)
        ->and($request->current_stage)->toBe(WorkflowStage::ProjectSponsor->value)
        ->and($request->returned_from_stage)->toBeNull();
});

it('falls back to the owner when the returning stage was never recorded', function () {
    // Defensive: incomplete data must not skip an approval that was never given.
    $request = draftRequest($this->requestor, $this->owner);
    $this->workflow->submit($request);
    $this->workflow->returnForAmendment($request->fresh(), 'Reason.');

    $request->fresh()->forceFill(['returned_from_stage' => null])->save();

    $resumed = $this->workflow->resubmit($request->fresh());

    expect($resumed)->toBe(WorkflowStage::ProjectOwner);
});

// ---- Prohibited transitions (§7.2) -----------------------------------------

it('refuses to close a request that still has a pending decision', function () {
    /*
     * BR-006. This is a PROHIBITED transition, and it is the one worth testing: a
     * request closed while a decision is outstanding would have no recorded outcome,
     * and the audit trail would show a gap where the decision should be.
     */
    $request = draftRequest($this->requestor, $this->owner);
    $this->workflow->submit($request);

    expect(fn () => $this->workflow->close($request->fresh()))
        ->toThrow(RuntimeException::class, 'still awaiting a decision');
});

it('closes a request once no decision is pending', function () {
    $request = draftRequest($this->requestor, $this->owner);
    $this->workflow->submit($request);

    // Decide the outstanding task, as an approver would.
    ApprovalTask::where('request_id', $request->id)->pending()->update(['decided_at' => now()]);

    $this->workflow->close($request->fresh(), 'approved');
    $request->refresh();

    expect($request->status)->toBe(RequestStatus::Closed->value)
        ->and($request->closed_at)->not->toBeNull()
        ->and($request->outcome)->toBe('approved');
});

it('never leaves a transition without a history row', function () {
    /*
     * The invariant the whole design exists to protect. Every transition the service
     * performs must be recorded — an unrecorded state change is an unauditable
     * request, which is the one outcome this system must not produce.
     */
    $request = draftRequest($this->requestor, $this->owner);

    $this->workflow->submit($request);
    $this->workflow->advance($request->fresh(), WorkflowStage::ProjectSponsor, 'approve');
    $this->workflow->returnForAmendment($request->fresh(), 'Reason.');
    $this->workflow->resubmit($request->fresh());

    $histories = WorkflowHistory::where('request_id', $request->id)->pluck('action')->all();

    expect($histories)->toBe(['submit', 'approve', 'return', 'resubmit']);
});
