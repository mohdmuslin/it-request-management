<?php

use App\Enums\Decision;
use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Enums\WorkflowStage;
use App\Livewire\Approvals\Index as ApprovalsIndex;
use App\Livewire\Delegations\Index as DelegationsIndex;
use App\Models\ApprovalTask;
use App\Models\Delegation;
use App\Models\Department;
use App\Models\ItRequest;
use App\Services\RequestNumberService;
use App\Services\WorkflowService;
use Database\Seeders\ReferenceDataSeeder;
use Livewire\Livewire;

/**
 * The approvals queue and the delegation screen.
 *
 * The queue is the one screen where getting the scoping wrong means a decision is
 * made by somebody with no authority — so most of what follows is about who must
 * NOT see a task.
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
        'business_need' => 'The file server is out of warranty and has failed twice.',
    ]);
});

// ---- The queue --------------------------------------------------------------

it('shows the request to the approver whose turn it is', function () {
    $this->workflow->submit($this->request);
    $this->actingAs($this->owner);

    Livewire::test(ApprovalsIndex::class)
        ->assertSee('Replacement of the ageing file server')
        ->assertSee('Project Owner');
});

it('hides the request from the approver whose turn has not come', function () {
    /*
     * The Sponsor can see the request itself — they are named on it — but it is not
     * in their queue. A queue that shows work a person cannot act on trains them to
     * ignore it.
     */
    $this->workflow->submit($this->request);
    $this->actingAs($this->sponsor);

    Livewire::test(ApprovalsIndex::class)
        ->assertDontSee('Replacement of the ageing file server');
});

it('hides the queue from an unrelated employee', function () {
    $this->workflow->submit($this->request);
    $this->actingAs(asUser(UserRole::Requestor));

    Livewire::test(ApprovalsIndex::class)
        ->assertDontSee('Replacement of the ageing file server');
});

it('shows an overdue task and counts it', function () {
    $this->workflow->submit($this->request);

    // Force the due date into the past.
    ApprovalTask::where('request_id', $this->request->id)
        ->update(['due_at' => now()->subDays(5)]);

    $this->actingAs($this->owner);

    Livewire::test(ApprovalsIndex::class)
        ->assertSee('Overdue since')
        ->assertSee('1 past target');
});

it('filters to overdue only', function () {
    $this->workflow->submit($this->request);

    ApprovalTask::where('request_id', $this->request->id)
        ->update(['due_at' => now()->addDays(5)]);

    $this->actingAs($this->owner);

    Livewire::test(ApprovalsIndex::class)
        ->assertSee('Replacement of the ageing file server')
        ->set('overdueOnly', true)
        ->assertDontSee('Replacement of the ageing file server')
        ->assertSee('Nothing is overdue');
});

it('distinguishes an empty filter from an empty queue', function () {
    // Two different states with two different next actions, exactly as the request
    // list does.
    $this->actingAs($this->owner);

    Livewire::test(ApprovalsIndex::class)
        ->set('overdueOnly', true)
        ->assertSee('Nothing is overdue')
        ->set('overdueOnly', false)
        ->assertSee('Nothing is waiting on your decision');
});

// ---- Deciding from the queue ------------------------------------------------

it('records a decision through the queue', function () {
    $this->workflow->submit($this->request);
    $this->actingAs($this->owner);

    $task = ApprovalTask::where('request_id', $this->request->id)->pending()->first();

    Livewire::test(ApprovalsIndex::class)
        ->call('startDecision', $task->id)
        ->set('decision', Decision::Approved->value)
        ->call('recordDecision')
        ->assertHasNoErrors();

    $this->request->refresh();

    expect($this->request->current_stage)->toBe(WorkflowStage::ProjectSponsor->value);
});

it('requires a comment in the panel when returning', function () {
    $this->workflow->submit($this->request);
    $this->actingAs($this->owner);

    $task = ApprovalTask::where('request_id', $this->request->id)->pending()->first();

    Livewire::test(ApprovalsIndex::class)
        ->call('startDecision', $task->id)
        ->set('decision', Decision::Returned->value)
        ->set('comments', '')
        ->call('recordDecision')
        ->assertHasErrors(['comments']);
});

it('requires conditions in the panel for a conditional approval', function () {
    $this->workflow->submit($this->request);
    $this->actingAs($this->owner);

    $task = ApprovalTask::where('request_id', $this->request->id)->pending()->first();

    Livewire::test(ApprovalsIndex::class)
        ->call('startDecision', $task->id)
        ->set('decision', Decision::ApprovedWithConditions->value)
        ->set('conditions', '')
        ->call('recordDecision')
        ->assertHasErrors(['conditions']);
});

it('refuses to open the decision panel for a task that is not yours', function () {
    /*
     * The queue filters by the same rule, but this asserts the ACTION is guarded too.
     * Fetching a task by id and trusting the queue to have filtered it is how a
     * crafted call decides somebody else's request.
     */
    $this->workflow->submit($this->request);
    $this->actingAs($this->sponsor);

    $task = ApprovalTask::where('request_id', $this->request->id)->pending()->first();

    Livewire::test(ApprovalsIndex::class)
        ->call('startDecision', $task->id)
        ->assertForbidden();
});

it('refuses to record a decision through the queue when not authorised', function () {
    /*
     * ASSERTED AS AN OUTCOME, NOT AS A STATUS CODE, and the distinction is the point.
     *
     * The obvious assertion here was 403, and it failed with 200. The reason is worth
     * knowing: `render()` re-filters the queue against the same rule the service
     * uses, and clears `decidingTaskId` when the task is not in it — so by the time
     * `recordDecision` runs, no panel is open and it refuses with a message rather
     * than a 403.
     *
     * That is the self-healing branch doing its job, and it means the 403 inside
     * `recordDecision` is a second line of defence rather than the first. Asserting
     * the status code would be asserting the mechanism; what actually matters is that
     * an unauthorised decision does not happen. So this checks the two things that
     * would be true if it did: the request moved, or the task was decided.
     */
    $this->workflow->submit($this->request);
    $this->actingAs($this->sponsor);

    $task = ApprovalTask::where('request_id', $this->request->id)->pending()->first();
    $stageBefore = $this->request->fresh()->current_stage;

    try {
        Livewire::test(ApprovalsIndex::class)
            ->set('decidingTaskId', $task->id)
            ->set('decision', Decision::Approved->value)
            ->call('recordDecision');
    } catch (Throwable $e) {
        // A 403 is an acceptable refusal. Anything else is not — rethrown below.
        if (! str_contains($e->getMessage(), '403')) {
            throw $e;
        }
    }

    expect($this->request->fresh()->current_stage)->toBe($stageBefore)
        ->and($task->fresh()->decided_at)->toBeNull()
        ->and($task->fresh()->decision)->toBeNull();
});

// ---- Delegation through the queue -------------------------------------------

it('shows a delegate the task they are acting on', function () {
    $delegate = asUser(UserRole::ProjectOwner);

    $this->workflow->submit($this->request);

    Delegation::create([
        'approver_id' => $this->owner->id,
        'delegate_id' => $delegate->id,
        'created_by' => $this->owner->id,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addWeek(),
    ]);

    $this->actingAs($delegate);

    Livewire::test(ApprovalsIndex::class)
        ->assertSee('Replacement of the ageing file server')
        // The borrowed authority is visible per row, not only in the banner.
        ->assertSee('Acting for '.$this->owner->name)
        ->assertSee('You are acting for someone');
});

// ---- The delegation screen --------------------------------------------------

it('creates a delegation', function () {
    $delegate = asUser(UserRole::ProjectOwner);
    $this->actingAs($this->owner);

    Livewire::test(DelegationsIndex::class)
        ->set('delegate_id', $delegate->id)
        ->set('starts_at', now()->toDateString())
        ->set('ends_at', now()->addWeek()->toDateString())
        ->set('reason', 'Annual leave')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('delegations', [
        'approver_id' => $this->owner->id,
        'delegate_id' => $delegate->id,
    ]);
});

it('refuses a delegation to yourself', function () {
    // A no-op that would put an "acting for" marker on your own decisions.
    $this->actingAs($this->owner);

    Livewire::test(DelegationsIndex::class)
        ->set('delegate_id', $this->owner->id)
        ->set('starts_at', now()->toDateString())
        ->set('ends_at', now()->addWeek()->toDateString())
        ->call('save')
        ->assertHasErrors(['delegate_id']);
});

it('refuses a delegation that ends before it starts', function () {
    $delegate = asUser(UserRole::ProjectOwner);
    $this->actingAs($this->owner);

    Livewire::test(DelegationsIndex::class)
        ->set('delegate_id', $delegate->id)
        ->set('starts_at', '2026-12-01')
        ->set('ends_at', '2026-11-01')
        ->call('save')
        ->assertHasErrors(['ends_at']);
});

it('requires an end date', function () {
    /*
     * Cover that never expires is authority nobody remembers to take back — and that
     * is how the current process ended up with no delegation in use at all.
     */
    $delegate = asUser(UserRole::ProjectOwner);
    $this->actingAs($this->owner);

    Livewire::test(DelegationsIndex::class)
        ->set('delegate_id', $delegate->id)
        ->set('starts_at', now()->toDateString())
        ->set('ends_at', '')
        ->call('save')
        ->assertHasErrors(['ends_at']);
});

it('forces a non-administrator to delegate only their own authority', function () {
    /*
     * Forced, not merely validated. If `approver_id` were honoured from the form, a
     * non-administrator could delegate authority they do not hold.
     */
    $other = asUser(UserRole::ProjectOwner);
    $delegate = asUser(UserRole::ProjectOwner);

    $this->actingAs($this->owner);

    Livewire::test(DelegationsIndex::class)
        ->set('approver_id', $other->id)   // attempt to delegate somebody else's authority
        ->set('delegate_id', $delegate->id)
        ->set('starts_at', now()->toDateString())
        ->set('ends_at', now()->addWeek()->toDateString())
        ->call('save')
        ->assertHasNoErrors();

    // Recorded against the actor, not the forged id.
    $this->assertDatabaseHas('delegations', [
        'approver_id' => $this->owner->id,
        'delegate_id' => $delegate->id,
    ]);

    $this->assertDatabaseMissing('delegations', ['approver_id' => $other->id]);
});

it('lets an administrator delegate on somebody else\'s behalf', function () {
    // The alternative is a request stalled behind somebody already unreachable.
    $admin = asUser(UserRole::Administrator);
    $delegate = asUser(UserRole::ProjectOwner);

    $this->actingAs($admin);

    Livewire::test(DelegationsIndex::class)
        ->set('approver_id', $this->owner->id)
        ->set('delegate_id', $delegate->id)
        ->set('starts_at', now()->toDateString())
        ->set('ends_at', now()->addWeek()->toDateString())
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('delegations', [
        'approver_id' => $this->owner->id,
        'delegate_id' => $delegate->id,
        'created_by' => $admin->id,
    ]);
});

it('revokes a delegation without deleting it', function () {
    /*
     * The row must survive because decisions already made under it cite it through
     * `approval_tasks.delegated_from_id`. Deleting would leave those decisions
     * pointing at nothing, and BR-008 requires the authorising arrangement to remain
     * explainable.
     */
    $delegate = asUser(UserRole::ProjectOwner);

    $delegation = Delegation::create([
        'approver_id' => $this->owner->id,
        'delegate_id' => $delegate->id,
        'created_by' => $this->owner->id,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addWeek(),
    ]);

    $this->actingAs($this->owner);

    Livewire::test(DelegationsIndex::class)
        ->call('revoke', $delegation->id)
        ->assertHasNoErrors();

    $this->assertDatabaseHas('delegations', [
        'id' => $delegation->id,
        'revoked_by' => $this->owner->id,
    ]);

    $delegation->refresh();

    expect($delegation->revoked_at)->not->toBeNull()
        ->and($delegation->isActive())->toBeFalse();
});

it('refuses revocation by an unrelated user', function () {
    $delegate = asUser(UserRole::ProjectOwner);

    $delegation = Delegation::create([
        'approver_id' => $this->owner->id,
        'delegate_id' => $delegate->id,
        'created_by' => $this->owner->id,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addWeek(),
    ]);

    // The delegate benefits from the delegation, so they must not be able to extend
    // or protect it — and a stranger certainly must not end it.
    $this->actingAs(asUser(UserRole::Requestor));

    Livewire::test(DelegationsIndex::class)
        ->call('revoke', $delegation->id)
        ->assertForbidden();

    expect($delegation->fresh()->revoked_at)->toBeNull();
});
