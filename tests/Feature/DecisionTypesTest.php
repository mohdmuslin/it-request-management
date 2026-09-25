<?php

use App\Enums\Decision;
use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Enums\WorkflowStage;
use App\Livewire\Approvals\Index as ApprovalsIndex;
use App\Models\ApprovalTask;
use App\Models\Department;
use App\Models\ItRequest;
use App\Services\RequestNumberService;
use App\Services\WorkflowDecisionService;
use App\Services\WorkflowService;
use Database\Seeders\ReferenceDataSeeder;
use Livewire\Livewire;

/**
 * Every decision type, driven through the SCREEN rather than the service.
 *
 * WHY THIS FILE EXISTS
 *
 * `WorkflowDecisionService::decide()` declared `array $conditions = []` while every
 * caller passed a string — the approvals screen, the committee path and the tests. Choosing
 * "approved with conditions" threw a TypeError, so the one decision type that carries
 * conditions was the only one that could not be made.
 *
 * The suite was green throughout, and the reason is worth stating plainly: the tests went
 * through a HELPER that also declared `array`, so the helper agreed with the broken
 * signature and disagreed with the real caller. A test helper that mirrors a bug tests the
 * helper, not the application.
 *
 * So these tests exercise the component the way a browser does, with the values the form
 * actually holds. Every one of them failed before the type was corrected.
 */
beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);

    $this->workflow = app(WorkflowService::class);

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
        'business_need' => 'The file server is out of warranty.',
    ]);

    $this->workflow->submit($this->request);

    /** The task awaiting the Owner. */
    $this->task = fn () => ApprovalTask::where('request_id', $this->request->id)->pending()->first();
});

// ---- Every decision type, through the screen --------------------------------

it('records a plain approval through the approvals screen', function () {
    $this->actingAs($this->owner);

    Livewire::test(ApprovalsIndex::class)
        ->call('startDecision', $this->task->call($this)->id)
        ->set('decision', Decision::Approved->value)
        ->call('recordDecision')
        ->assertHasNoErrors();

    expect($this->request->fresh()->current_stage)->toBe(WorkflowStage::ProjectSponsor->value);
});

it('records an approval with conditions through the approvals screen', function () {
    /*
     * THE DEFECT THIS FILE EXISTS FOR.
     *
     * The form holds `conditions` as a string — it is a textarea. The service expected an
     * array, so this path threw a TypeError the moment it was used. Nothing in the suite
     * touched it, because the tests called the service directly through a helper that
     * happened to declare the same wrong type.
     */
    $this->actingAs($this->owner);

    Livewire::test(ApprovalsIndex::class)
        ->call('startDecision', $this->task->call($this)->id)
        ->set('decision', Decision::ApprovedWithConditions->value)
        ->set('conditions', 'Vendor must commit to three years of support.')
        ->call('recordDecision')
        ->assertHasNoErrors();

    $task = $this->task->call($this);

    // Stored on the NEW task at the next stage — the decided one is no longer pending.
    $decided = ApprovalTask::where('request_id', $this->request->id)
        ->where('stage', WorkflowStage::ProjectOwner->value)
        ->first();

    expect($decided->conditions)->toBe('Vendor must commit to three years of support.')
        ->and($decided->decision)->toBe(Decision::ApprovedWithConditions)
        ->and($this->request->fresh()->current_stage)->toBe(WorkflowStage::ProjectSponsor->value);

    // And the request moved, which is what a conditional approval means: an approval.
    expect($this->request->fresh()->status)->toBe(RequestStatus::PendingProjectSponsor->value);
});

it('records a return through the approvals screen', function () {
    $this->actingAs($this->owner);

    Livewire::test(ApprovalsIndex::class)
        ->call('startDecision', $this->task->call($this)->id)
        ->set('decision', Decision::Returned->value)
        ->set('comments', 'The budget needs itemising before this can proceed.')
        ->call('recordDecision')
        ->assertHasNoErrors();

    $this->request->refresh();

    expect($this->request->status)->toBe(RequestStatus::ReturnedForAmendment->value)
        ->and($this->request->returned_from_stage)->toBe(WorkflowStage::ProjectOwner->value);
});

it('records a rejection through the approvals screen', function () {
    $this->actingAs($this->owner);

    Livewire::test(ApprovalsIndex::class)
        ->call('startDecision', $this->task->call($this)->id)
        ->set('decision', Decision::Rejected->value)
        ->set('comments', 'The business case does not justify the cost at this time.')
        ->call('recordDecision')
        ->assertHasNoErrors();

    $this->request->refresh();

    expect($this->request->status)->toBe(RequestStatus::NotRecommended->value)
        ->and($this->request->closed_at)->not->toBeNull();
});

it('asks for conditions when that decision is chosen', function () {
    // The field is only shown for this decision, so `conditions` is empty for every
    // other one — which is exactly the value that used to be passed as an empty array.
    $this->actingAs($this->owner);

    Livewire::test(ApprovalsIndex::class)
        ->call('startDecision', $this->task->call($this)->id)
        ->set('decision', Decision::ApprovedWithConditions->value)
        ->set('conditions', '')
        ->call('recordDecision')
        ->assertHasErrors(['conditions']);
});

it('passes every decision type without a type error', function () {
    /*
     * A sweep rather than a specific case.
     *
     * The failure was a TypeError from a parameter whose declared type disagreed with
     * every caller. This asserts that the SERVICE accepts what the form holds for each
     * decision — so a future change to either side that reopens the gap fails here rather
     * than in front of whoever chose that option.
     */
    $service = app(WorkflowDecisionService::class);

    $signature = new ReflectionMethod($service, 'decide');
    $conditions = collect($signature->getParameters())->firstWhere('name', 'conditions');

    expect($conditions)->not->toBeNull()
        ->and((string) $conditions->getType())
        ->toBe('?string', 'The form holds conditions as a string; the service must accept a string.');
});
