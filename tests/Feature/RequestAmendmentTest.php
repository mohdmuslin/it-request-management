<?php

use App\Enums\Decision;
use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Enums\WorkflowStage;
use App\Livewire\Requests\Create;
use App\Livewire\Requests\Show;
use App\Models\ApprovalTask;
use App\Models\Department;
use App\Models\ItRequest;
use App\Models\WorkflowHistory;
use App\Services\RequestNumberService;
use App\Services\WorkflowDecisionService;
use App\Services\WorkflowService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/**
 * Editing and resubmitting — the path from "returned" back into the chain.
 *
 * WHY THIS FILE EXISTS
 *
 * Every one of these defects was found by clicking through the application, and
 * none by the tests that already existed. The existing tests drove components
 * directly and never followed a link, so they could not see that "Edit draft"
 * pointed at the create wizard, nor that a returned request had no way back in.
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
        'business_plan_status' => 'aligned',
        'business_plan_reference' => 'IT Roadmap 2026',
        'urgency' => 'medium',
        'impact_if_not_implemented' => 'Finance goes offline during month-end close.',
    ]);
});

/** Put the request into a returned state at a given stage. */
function returnAt(string $stage): void
{
    $request = test()->request;

    test()->workflow->submit($request);

    if ($stage === WorkflowStage::ProjectSponsor->value) {
        // Owner approves first, so the return comes from the Sponsor.
        test()->actingAs(test()->owner);
        app(WorkflowDecisionService::class)->decide(
            request: $request->fresh(),
            actor: test()->owner,
            decision: Decision::Approved,
        );
        test()->actingAs(test()->sponsor);
    } else {
        test()->actingAs(test()->owner);
    }

    app(WorkflowDecisionService::class)->decide(
        request: $request->fresh(),
        actor: auth()->user(),
        decision: Decision::Returned,
        comments: 'The budget needs itemising.',
    );
}

// ---- The edit route ---------------------------------------------------------

it('has an edit route that exists', function () {
    /*
     * THE DEFECT, ASSERTED DIRECTLY.
     *
     * "Edit draft" linked to `requests.create`, so it opened a blank wizard and the
     * request the user meant to edit was never passed. The route name did not exist
     * at all, so nothing could have linked to it correctly.
     */
    expect(Route::has('requests.edit'))->toBeTrue();
});

it('loads an existing draft into the wizard', function () {
    $this->actingAs($this->requestor);

    Livewire::test(Create::class, ['request' => $this->request])
        ->assertSet('requestId', $this->request->id)
        ->assertSet('title', 'Replacement of the ageing file server')
        ->assertSet('request_date', now()->toDateString());
});

it('opens the edit screen for a draft and saves changes', function () {
    $this->actingAs($this->requestor);

    Livewire::test(Create::class, ['request' => $this->request])
        ->set('title', 'Replacement of the file server (revised)')
        ->call('saveDraft')
        ->assertHasNoErrors();

    expect($this->request->fresh()->title)->toBe('Replacement of the file server (revised)');
});

it('updates the existing request rather than creating a second one', function () {
    // A wizard that always creates would turn one draft into two on every save.
    $this->actingAs($this->requestor);

    $before = ItRequest::count();

    Livewire::test(Create::class, ['request' => $this->request])
        ->set('title', 'Edited in place')
        ->call('saveDraft');

    expect(ItRequest::count())->toBe($before);
});

it('refuses to edit a request that is in the approval chain', function () {
    // An approver reading the request must be deciding on the text they were given.
    $this->workflow->submit($this->request);
    $this->actingAs($this->requestor);

    Livewire::test(Create::class, ['request' => $this->request->fresh()])
        ->assertForbidden();
});

it('refuses to edit somebody else\'s request', function () {
    $this->actingAs(asUser(UserRole::Requestor));

    Livewire::test(Create::class, ['request' => $this->request])
        ->assertForbidden();
});

// ---- Resubmission (BR-007) --------------------------------------------------

it('resubmits a returned request to the stage that returned it', function () {
    /*
     * BR-007, asserted through the component rather than the service.
     *
     * The service was already correct and covered. What was missing was any way to
     * REACH it: the screen offered "Edit draft" and "Withdraw" but no resubmit, so
     * a requestor whose request came back had no way to send it forward again.
     */
    returnAt(WorkflowStage::ProjectSponsor->value);

    expect($this->request->fresh()->status)->toBe(RequestStatus::ReturnedForAmendment->value);

    $this->actingAs($this->requestor);

    Livewire::test(Show::class, ['request' => $this->request->fresh()])
        ->call('resubmit')
        ->assertHasNoErrors();

    $resumed = $this->request->fresh();

    expect($resumed->current_stage)->toBe(WorkflowStage::ProjectSponsor->value)
        // The Owner's approval is NOT repeated — it already happened.
        ->and(ApprovalTask::where('request_id', $resumed->id)->where('stage', WorkflowStage::ProjectOwner->value)->count())->toBe(1);
});

it('resubmits to the owner when the owner returned it', function () {
    returnAt(WorkflowStage::ProjectOwner->value);
    $this->actingAs($this->requestor);

    Livewire::test(Show::class, ['request' => $this->request->fresh()])
        ->call('resubmit');

    expect($this->request->fresh()->current_stage)->toBe(WorkflowStage::ProjectOwner->value);
});

it('does NOT restart the chain when a returned request is edited and submitted', function () {
    /*
     * THE SECOND DEFECT, AND THE SUBTLER ONE.
     *
     * `persist()` called `submit()` unconditionally on the submit path. `submit()`
     * sets the stage to Project Owner, so editing a request that the SPONSOR had
     * returned and then submitting it sent it back to the Owner — silently
     * discarding the stage that returned it, and contradicting BR-007.
     *
     * The symptom would have been a request appearing at the Owner with no
     * indication why, and the Sponsor having to approve the same request twice.
     */
    returnAt(WorkflowStage::ProjectSponsor->value);

    $this->actingAs($this->requestor);

    Livewire::test(Create::class, ['request' => $this->request->fresh()])
        ->set('budget_amount', '48000.00')
        ->call('submit')
        ->assertHasNoErrors();

    $resumed = $this->request->fresh();

    expect($resumed->current_stage)->toBe(WorkflowStage::ProjectSponsor->value)
        ->and($resumed->status)->toBe(RequestStatus::PendingProjectSponsor->value);
});

it('clears the returned-from marker once resubmitted', function () {
    // Left set, a second return would resume at a stage that no longer applies.
    returnAt(WorkflowStage::ProjectSponsor->value);
    $this->actingAs($this->requestor);

    Livewire::test(Show::class, ['request' => $this->request->fresh()])
        ->call('resubmit');

    expect($this->request->fresh()->returned_from_stage)->toBeNull();
});

it('refuses a resubmit when the request is not returned', function () {
    $this->workflow->submit($this->request);
    $this->actingAs($this->requestor);

    Livewire::test(Show::class, ['request' => $this->request->fresh()])
        ->call('resubmit')
        ->assertForbidden();
});

it('refuses a resubmit by somebody who is not the requestor', function () {
    returnAt(WorkflowStage::ProjectOwner->value);
    $this->actingAs($this->owner);

    Livewire::test(Show::class, ['request' => $this->request->fresh()])
        ->call('resubmit')
        ->assertForbidden();
});

it('keeps the amendment visible after resubmission', function () {
    // The trail must still show what was returned, why, and that it came back.
    returnAt(WorkflowStage::ProjectSponsor->value);
    $this->actingAs($this->requestor);

    Livewire::test(Show::class, ['request' => $this->request->fresh()])
        ->call('resubmit');

    $actions = WorkflowHistory::where('request_id', $this->request->id)
        ->pluck('action')->all();

    expect($actions)->toContain('returned')
        ->and($actions)->toContain('resubmit');
});
