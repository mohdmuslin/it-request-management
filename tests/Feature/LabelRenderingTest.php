<?php

use App\Enums\RecommendationOutcome;
use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Enums\WorkflowStage;
use App\Livewire\Dashboard;
use App\Livewire\Requests\Show;
use App\Models\ApprovalTask;
use App\Models\Department;
use App\Models\ItRequest;
use App\Models\Recommendation;
use App\Models\ReviewUnit;
use App\Models\WorkflowHistory;
use App\Services\RequestNumberService;
use Database\Seeders\ReferenceDataSeeder;
use Livewire\Livewire;

/**
 * Raw enum values must never reach the screen.
 *
 * WHY THIS FILE EXISTS
 *
 * Three separate places rendered the raw database value instead of a label:
 * the dashboard's Stage column showed `committee_decision`, the governance record
 * showed `recommended_with_conditions`, and the history timeline showed
 * `project_owner → returned_for_amendment`.
 *
 * Every one was accurate and none was readable — and in the dashboard's case it was
 * a table a manager reads. All three passed the test suite, because no test asserted
 * what the text SAID; they asserted that rows existed.
 *
 * `assertDontSee` on the raw value is the assertion that catches this class, so it is
 * applied to each surface that renders a status-bearing column.
 */
beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);

    $this->requestor = asUser(UserRole::Requestor);
    $this->owner = asUser(UserRole::ProjectOwner);

    $this->department = Department::create(['code' => 'ICT', 'name' => 'Information Technology']);

    $this->request = ItRequest::create([
        'request_no' => app(RequestNumberService::class)->next(),
        'title' => 'Warehouse management system',
        'request_date' => now()->toDateString(),
        'requestor_id' => $this->requestor->id,
        'department_id' => $this->department->id,
        'project_owner_id' => $this->owner->id,
        'status' => RequestStatus::PendingCommitteeDecision->value,
        'current_stage' => WorkflowStage::CommitteeDecision->value,
        'business_need' => 'Stock is tracked on spreadsheets.',
    ]);
});

it('shows a stage label on the dashboard, not the stage code', function () {
    ApprovalTask::create([
        'request_id' => $this->request->id,
        'stage' => WorkflowStage::CommitteeDecision->value,
        'sequence' => 1,
        'approver_id' => $this->owner->id,
        'due_at' => now()->addDays(5),
    ]);

    $this->actingAs($this->owner);

    Livewire::test(Dashboard::class)
        ->assertSee('Committee Decision')
        ->assertDontSee('committee_decision');
});

it('shows a stage label in the history, not the stage code', function () {
    // A requestor reading their own timeline should not be shown database values.
    WorkflowHistory::create([
        'request_id' => $this->request->id,
        'from_stage' => WorkflowStage::ProjectOwner->value,
        'to_stage' => WorkflowStage::ProjectSponsor->value,
        'action' => 'approved',
        'performed_by' => $this->owner->id,
    ]);

    $this->actingAs($this->requestor);

    /*
     * Asserted as two labels rather than one exact string.
     *
     * The markup between them is Blade's own line breaks, so an exact match on
     * "Project Owner → Project Sponsor" fails on whitespace while the rendering is
     * perfectly correct. Asserting the labels and the absence of the codes is the
     * assertion that actually describes the requirement.
     */
    Livewire::test(Show::class, ['request' => $this->request->fresh()])
        ->assertSee('Project Owner')
        ->assertSee('Project Sponsor')
        ->assertDontSee('project_owner')
        ->assertDontSee('project_sponsor');
});

it('shows a status label in the history for a return', function () {
    /*
     * A return records a STATUS rather than a stage, because the request has no stage
     * while the requestor works on it. The timeline must still read as English.
     */
    WorkflowHistory::create([
        'request_id' => $this->request->id,
        'from_stage' => WorkflowStage::ProjectSponsor->value,
        'to_stage' => RequestStatus::ReturnedForAmendment->value,
        'action' => 'returned',
        'performed_by' => $this->owner->id,
    ]);

    $this->actingAs($this->requestor);

    Livewire::test(Show::class, ['request' => $this->request->fresh()])
        ->assertSee('Project Sponsor')
        ->assertSee('Returned for Amendment')
        ->assertDontSee('returned_for_amendment');
});

it('shows a recommendation label, not the enum value', function () {
    $unit = ReviewUnit::orderBy('sort_order')->first();

    Recommendation::create([
        'request_id' => $this->request->id,
        'review_unit_id' => $unit->id,
        'reviewer_id' => $this->owner->id,
        'recommendation' => RecommendationOutcome::RecommendedWithConditions->value,
        'conditions' => 'After the platform migration.',
        'version_no' => 1,
        'submitted_at' => now(),
    ]);

    $this->actingAs($this->requestor);

    Livewire::test(Show::class, ['request' => $this->request->fresh()])
        ->assertSee('Recommended with conditions')
        ->assertDontSee('recommended_with_conditions');
});

it('still shows an unrecognised value rather than hiding the transition', function () {
    /*
     * The mapping falls back to the raw value. Hiding an unmappable transition would
     * be worse than showing a code: the trail would have a gap, and a gap in an audit
     * trail is a bigger problem than an ugly label.
     */
    WorkflowHistory::create([
        'request_id' => $this->request->id,
        'from_stage' => 'something_unknown',
        'to_stage' => WorkflowStage::Closure->value,
        'action' => 'legacy',
        'performed_by' => null,
    ]);

    $this->actingAs($this->requestor);

    Livewire::test(Show::class, ['request' => $this->request->fresh()])
        ->assertSee('something_unknown')
        ->assertSee('Closure');
});
