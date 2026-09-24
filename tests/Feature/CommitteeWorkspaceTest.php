<?php

use App\Enums\RecommendationOutcome;
use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Enums\WorkflowStage;
use App\Livewire\Committee\Index as CommitteeIndex;
use App\Livewire\Governance\Index as GovernanceIndex;
use App\Models\ApprovalTask;
use App\Models\Department;
use App\Models\GovernanceRoute;
use App\Models\ItRequest;
use App\Models\Recommendation;
use App\Models\ReviewUnit;
use App\Services\RequestNumberService;
use Database\Seeders\ReferenceDataSeeder;
use Livewire\Livewire;

/**
 * The committee workspace, and the governance workspace's view of it.
 *
 * WHY THE GOVERNANCE WORKSPACE IS TESTED HERE
 *
 * It reported "0 requests in the governance process" while a Full-route request was
 * sitting with the committee. That is a queue lying to the person whose job it is to
 * watch the process, and it happened because the tab list did not include the
 * committee stage — the request had not finished, but it had also left every tab the
 * screen knew about.
 */
beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);

    $this->requestor = asUser(UserRole::Requestor);
    $this->owner = asUser(UserRole::ProjectOwner);
    $this->secretary = asUser(UserRole::CommitteeSecretariat);
    $this->reviewer = asUser(UserRole::GovernanceReviewer);

    $this->department = Department::create(['code' => 'ICT', 'name' => 'Information Technology']);
    $this->fullRoute = GovernanceRoute::where('code', 'full')->first();

    $this->request = ItRequest::create([
        'request_no' => app(RequestNumberService::class)->next(),
        'title' => 'Warehouse management system',
        'request_date' => now()->toDateString(),
        'requestor_id' => $this->requestor->id,
        'department_id' => $this->department->id,
        'project_owner_id' => $this->owner->id,
        'governance_route_id' => $this->fullRoute->id,
        'budget_amount' => '165000.00',
        'status' => RequestStatus::PendingCommitteeDecision->value,
        'current_stage' => WorkflowStage::CommitteeDecision->value,
        'business_need' => 'Stock is tracked on spreadsheets.',
    ]);

    ApprovalTask::create([
        'request_id' => $this->request->id,
        'stage' => WorkflowStage::CommitteeDecision->value,
        'sequence' => 1,
        'approver_id' => $this->secretary->id,
        'due_at' => now()->addDays(10),
    ]);
});

it('shows a Full-route request to the committee', function () {
    $this->actingAs($this->secretary);

    Livewire::test(CommitteeIndex::class)
        ->assertOk()
        ->assertSee('Warehouse management system')
        ->assertSee('Warehouse management system');
});

it('summarises the unit positions on the agenda', function () {
    /*
     * The committee decides on the technical advice, so it belongs on the agenda
     * rather than one click away.
     */
    $unit = ReviewUnit::orderBy('sort_order')->first();

    Recommendation::create([
        'request_id' => $this->request->id,
        'review_unit_id' => $unit->id,
        'reviewer_id' => $this->owner->id,
        'recommendation' => RecommendationOutcome::Recommended->value,
        'version_no' => 1,
        'submitted_at' => now(),
    ]);

    $this->actingAs($this->secretary);

    Livewire::test(CommitteeIndex::class)
        ->assertSee($unit->name)
        ->assertSee('Recommended');
});

it('calls out a unit that advised against', function () {
    /*
     * The thing a committee most needs to see before it approves. A concern buried in
     * a sub-page is a concern the committee did not weigh.
     */
    $unit = ReviewUnit::orderBy('sort_order')->first();

    Recommendation::create([
        'request_id' => $this->request->id,
        'review_unit_id' => $unit->id,
        'reviewer_id' => $this->owner->id,
        'recommendation' => RecommendationOutcome::NotRecommended->value,
        'evidence' => 'The integration effort has been underestimated.',
        'version_no' => 1,
        'submitted_at' => now(),
    ]);

    $this->actingAs($this->secretary);

    Livewire::test(CommitteeIndex::class)
        ->assertSee('Not recommended')
        ->assertSee('advised against');
});

it('excludes a request whose route does not require a committee', function () {
    /*
     * Filtered on `requires_committee`, not on the stage alone.
     *
     * The routing rule lives in the database so an administrator can change it. A
     * hardcoded stage check would show this body requests it has no authority over —
     * and the screen would look entirely legitimate.
     */
    $light = GovernanceRoute::where('code', 'light')->first();

    $this->request->forceFill(['governance_route_id' => $light->id])->save();

    $this->actingAs($this->secretary);

    Livewire::test(CommitteeIndex::class)
        ->assertDontSee('Warehouse management system')
        ->assertSee('Nothing is awaiting a committee decision');
});

it('does not show a request at another stage', function () {
    $this->request->forceFill(['current_stage' => WorkflowStage::Consolidation->value])->save();

    $this->actingAs($this->secretary);

    Livewire::test(CommitteeIndex::class)
        ->assertDontSee('Warehouse management system');
});

it('counts a committee-held request in the governance workspace', function () {
    /*
     * THE DEFECT THIS FILE EXISTS FOR. The workspace said "0 requests in the
     * governance process" while a request was with the committee — a queue lying to
     * the person whose job is to watch the process.
     */
    $this->actingAs($this->reviewer);

    $component = Livewire::test(GovernanceIndex::class);

    expect($component->viewData('total'))->toBe(1)
        ->and($component->viewData('counts')['committee_decision'])->toBe(1);

    $component->call('showStage', 'committee_decision')
        ->assertSee('Warehouse management system')
        ->assertSee('IT Investment Committee');
});
