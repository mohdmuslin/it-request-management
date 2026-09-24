<?php

use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Enums\WorkflowStage;
use App\Livewire\Requests\Index;
use App\Livewire\Requests\Show;
use App\Models\ApprovalTask;
use App\Models\Department;
use App\Models\ItRequest;
use App\Services\RequestNumberService;
use Database\Seeders\ReferenceDataSeeder;
use Livewire\Livewire;

/**
 * The request list and the request detail screen.
 *
 * The visibility rules matter most here: a list is where a leak is easiest, because
 * scoping it wrongly shows a row rather than throwing an error.
 */
beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);

    $this->requestor = asUser(UserRole::Requestor);
    $this->owner = asUser(UserRole::ProjectOwner);
    $this->stranger = asUser(UserRole::Requestor);

    $this->department = Department::create(['code' => 'ICT', 'name' => 'Information Technology']);

    $this->request = ItRequest::create([
        'request_no' => app(RequestNumberService::class)->next(),
        'title' => 'Replacement of the ageing file server',
        'request_date' => now()->toDateString(),
        'requestor_id' => $this->requestor->id,
        'department_id' => $this->department->id,
        'project_owner_id' => $this->owner->id,
        'status' => RequestStatus::Draft->value,
        'current_stage' => WorkflowStage::Submission->value,
        'business_need' => 'The file server is out of warranty and has failed twice.',
    ]);
});

// ---- The list ---------------------------------------------------------------

it('shows the requestor their own requests', function () {
    $this->actingAs($this->requestor);

    Livewire::test(Index::class)
        ->assertSee('Replacement of the ageing file server')
        ->assertSee($this->request->request_no);
});

it('hides another employee\'s requests', function () {
    /*
     * The leak this scoping exists to prevent. A list that is filtered in the view
     * rather than in the query still retrieves every row — the data is in memory and
     * one careless change away from being displayed.
     */
    $this->actingAs($this->stranger);

    Livewire::test(Index::class)
        ->assertDontSee('Replacement of the ageing file server')
        ->assertSee('You have no requests yet');
});

it('shows the request to the named owner', function () {
    $this->actingAs($this->owner);

    Livewire::test(Index::class)->assertSee('Replacement of the ageing file server');
});

it('filters by status', function () {
    $this->actingAs($this->requestor);

    Livewire::test(Index::class)
        ->set('status', RequestStatus::Draft->value)
        ->assertSee('Replacement of the ageing file server')
        ->set('status', RequestStatus::Closed->value)
        ->assertDontSee('Replacement of the ageing file server');
});

it('distinguishes an empty filter result from having no requests', function () {
    /*
     * Two different situations, two different actions: clear the filter, or raise a
     * request. Telling a user the wrong one sends them to the wrong place — and the
     * dashboard had exactly this defect, telling an administrator they had no role.
     */
    $this->actingAs($this->requestor);

    Livewire::test(Index::class)
        ->set('search', 'nothing matches this')
        ->assertSee('No requests match these filters')
        ->assertDontSee('You have no requests yet');
});

it('searches by request number', function () {
    $this->actingAs($this->requestor);

    Livewire::test(Index::class)
        ->set('search', $this->request->request_no)
        ->assertSee('Replacement of the ageing file server');
});

it('returns to page one when a filter changes', function () {
    // Searching from page 3 shows page 3 of the new results, which is usually empty
    // and reads as "no matches found".
    $this->actingAs($this->requestor);

    Livewire::test(Index::class)
        ->call('gotoPage', 2)
        ->assertSet('paginators.page', 2)
        ->set('search', 'server')
        ->assertSet('paginators.page', 1);
});

it('counts only the requests the user can see', function () {
    // A company-wide count is information a requestor is not entitled to.
    $this->actingAs($this->stranger);

    Livewire::test(Index::class)->assertSee('0 requests you can see');
});

// ---- The detail screen ------------------------------------------------------

it('opens for the requestor', function () {
    $this->actingAs($this->requestor);

    Livewire::test(Show::class, ['request' => $this->request])
        ->assertOk()
        ->assertSee('Replacement of the ageing file server');
});

it('shows the owner the request', function () {
    $this->actingAs($this->owner);

    Livewire::test(Show::class, ['request' => $this->request])->assertOk();
});

it('refuses the detail screen to an unrelated employee', function () {
    /*
     * THE DEFECT THE POLICY EXISTS TO CLOSE.
     *
     * The route is behind `auth` only, so before this any signed-in user could open
     * any request by changing the id in the URL. 403 rather than 404 — see the
     * trade-off in the component docblock.
     */
    $this->actingAs($this->stranger);

    Livewire::test(Show::class, ['request' => $this->request])->assertForbidden();
});

it('lets the requestor submit from the detail screen', function () {
    $this->actingAs($this->requestor);

    Livewire::test(Show::class, ['request' => $this->request])
        ->call('submit')
        ->assertHasNoErrors();

    $this->request->refresh();

    expect($this->request->status)->toBe(RequestStatus::Submitted->value)
        ->and($this->request->current_stage)->toBe(WorkflowStage::ProjectOwner->value);
});

it('refuses a submission by somebody who is not the requestor', function () {
    $this->actingAs($this->owner);

    // The owner can see it, and still cannot submit it on the requestor's behalf.
    Livewire::test(Show::class, ['request' => $this->request])
        ->call('submit')
        ->assertForbidden();
});

it('shows who the request is waiting on', function () {
    // "Waiting on" is more useful than a status label, which makes the reader work
    // out who "Pending Project Sponsor" actually refers to.
    $this->actingAs($this->requestor);

    $this->request->forceFill([
        'status' => RequestStatus::PendingProjectOwner->value,
        'current_stage' => WorkflowStage::ProjectOwner->value,
    ])->save();

    ApprovalTask::create([
        'request_id' => $this->request->id,
        'stage' => WorkflowStage::ProjectOwner->value,
        'sequence' => 1,
        'approver_id' => $this->owner->id,
        'due_at' => now()->addDays(3),
    ]);

    Livewire::test(Show::class, ['request' => $this->request])
        ->assertSee('Waiting on')
        ->assertSee($this->owner->name);
});
