<?php

use App\Enums\Decision;
use App\Enums\RecommendationOutcome;
use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Enums\WorkflowStage;
use App\Livewire\Governance\Index;
use App\Livewire\Requests\Show;
use App\Models\Classification;
use App\Models\Department;
use App\Models\GovernanceRoute;
use App\Models\ItRequest;
use App\Models\Tier;
use App\Services\GovernanceService;
use App\Services\RequestNumberService;
use App\Services\WorkflowDecisionService;
use App\Services\WorkflowService;
use Database\Seeders\ReferenceDataSeeder;
use Livewire\Livewire;

/**
 * The governance screens.
 *
 * Phase D and E each produced defects that only appeared in the browser, so the
 * governance panels are tested through the COMPONENT — the same path the screen
 * takes — rather than only through the service.
 */
beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);

    $this->workflow = app(WorkflowService::class);
    $this->governance = app(GovernanceService::class);

    $this->requestor = asUser(UserRole::Requestor);
    $this->owner = asUser(UserRole::ProjectOwner);
    $this->sponsor = asUser(UserRole::ProjectSponsor);
    $this->reviewer = asUser(UserRole::GovernanceReviewer);
    $this->hou = asUser(UserRole::Hou);
    $this->secretary = asUser(UserRole::CommitteeSecretariat);
    $this->auditor = asUser(UserRole::Auditor);

    $this->department = Department::create(['code' => 'ICT', 'name' => 'Information Technology']);
    $this->tier = Tier::orderBy('sort_order')->first();
    $this->classification = Classification::orderBy('name')->first();

    $this->request = ItRequest::create([
        'request_no' => app(RequestNumberService::class)->next(),
        'title' => 'Warehouse management system',
        'request_date' => now()->toDateString(),
        'requestor_id' => $this->requestor->id,
        'department_id' => $this->department->id,
        'project_owner_id' => $this->owner->id,
        'project_sponsor_id' => $this->sponsor->id,
        'status' => RequestStatus::Draft->value,
        'current_stage' => WorkflowStage::Submission->value,
        'business_need' => 'Stock is tracked on spreadsheets across three sites.',
    ]);
});

function atReview(): ItRequest
{
    test()->workflow->submit(test()->request);

    foreach ([[test()->owner], [test()->sponsor]] as $actor) {
        app(WorkflowDecisionService::class)->decide(
            request: test()->request->fresh(),
            actor: $actor[0],
            decision: Decision::Approved,
        );
    }

    return test()->request->fresh();
}

// ---- The assess panel -------------------------------------------------------

it('offers the assess panel to a governance reviewer', function () {
    atReview();
    $this->actingAs($this->reviewer);

    Livewire::test(Show::class, ['request' => $this->request->fresh()])
        ->assertSee('Assess completeness')
        ->call('openPanel', 'assess')
        ->assertSet('panel', 'assess');
});

it('pre-fills the assessment from the request', function () {
    // So the reviewer confirms rather than retypes what is already on screen.
    atReview();
    $this->request->fresh()->forceFill(['proposed_tier_id' => $this->tier->id])->save();

    $this->actingAs($this->reviewer);

    Livewire::test(Show::class, ['request' => $this->request->fresh()])
        ->assertSet('assess_tier_id', $this->tier->id);
});

it('assesses through the panel', function () {
    atReview();
    $this->actingAs($this->reviewer);

    Livewire::test(Show::class, ['request' => $this->request->fresh()])
        ->call('openPanel', 'assess')
        ->set('assess_tier_id', $this->tier->id)
        ->set('assess_classification_id', $this->classification->id)
        ->call('assess')
        ->assertHasNoErrors();

    expect($this->request->fresh()->current_stage)->toBe(WorkflowStage::TechnicalRecommendation->value);
});

it('refuses the assess panel to a requestor', function () {
    atReview();
    $this->actingAs($this->requestor);

    // A requestor cannot assess their own proposal — that would make the proposal
    // the decision, and there would be nothing to confirm.
    Livewire::test(Show::class, ['request' => $this->request->fresh()])
        ->call('openPanel', 'assess')
        ->assertForbidden();
});

it('refuses the assess panel to an auditor', function () {
    atReview();
    $this->actingAs($this->auditor);

    Livewire::test(Show::class, ['request' => $this->request->fresh()])
        ->call('openPanel', 'assess')
        ->assertForbidden();
});

it('shows the assessment error beside the field rather than throwing', function () {
    // The change-reason rule lives in the service; the panel must surface it as a
    // message, not a 500.
    $other = Tier::orderByDesc('sort_order')->first();
    atReview();
    $this->request->fresh()->forceFill(['proposed_tier_id' => $other->id])->save();

    $this->actingAs($this->reviewer);

    Livewire::test(Show::class, ['request' => $this->request->fresh()])
        ->call('openPanel', 'assess')
        ->set('assess_tier_id', $this->tier->id)
        ->set('assess_classification_id', $this->classification->id)
        ->set('assess_reason', '')
        ->call('assess')
        ->assertHasErrors(['assess_reason']);
});

// ---- The recommend panel ----------------------------------------------------

it('offers a reviewer only the units they belong to', function () {
    /*
     * Offering every unit would let a reviewer pick one they are not in and be
     * refused by the service — a message the form could have avoided entirely.
     */
    atReview();
    $this->governance->assess(
        request: $this->request->fresh(),
        assessor: $this->reviewer,
        tierId: $this->tier->id,
        classificationId: $this->classification->id,
    );

    $units = $this->governance->unitsFor($this->classification->id);
    $mine = asUser(UserRole::TechnicalReviewer);
    $mine->reviewUnits()->syncWithoutDetaching([$units[0]->id]);

    $this->actingAs($mine->fresh('reviewUnits'));

    $component = Livewire::test(Show::class, ['request' => $this->request->fresh()])
        ->call('openPanel', 'recommend');

    $offered = $component->viewData('myUnits');

    expect($offered)->toHaveCount(1)
        ->and($offered->first()->id)->toBe($units[0]->id);
});

it('tells a reviewer plainly when they are in no assigned unit', function () {
    atReview();
    $this->governance->assess(
        request: $this->request->fresh(),
        assessor: $this->reviewer,
        tierId: $this->tier->id,
        classificationId: $this->classification->id,
    );

    $outsider = asUser(UserRole::TechnicalReviewer);
    $this->actingAs($outsider);

    Livewire::test(Show::class, ['request' => $this->request->fresh()])
        ->call('openPanel', 'recommend')
        ->assertSee('do not belong to any of the units');
});

it('files a recommendation through the panel', function () {
    atReview();
    $this->governance->assess(
        request: $this->request->fresh(),
        assessor: $this->reviewer,
        tierId: $this->tier->id,
        classificationId: $this->classification->id,
    );

    $units = $this->governance->unitsFor($this->classification->id);
    $mine = asUser(UserRole::TechnicalReviewer);
    $mine->reviewUnits()->syncWithoutDetaching([$units[0]->id]);

    $this->actingAs($mine->fresh('reviewUnits'));

    Livewire::test(Show::class, ['request' => $this->request->fresh()])
        ->call('openPanel', 'recommend')
        ->set('recommend_unit_id', $units[0]->id)
        ->set('recommend_outcome', RecommendationOutcome::Recommended->value)
        ->set('recommend_evidence', 'No operational objection.')
        ->call('recommend')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('recommendations', [
        'request_id' => $this->request->id,
        'review_unit_id' => $units[0]->id,
        'recommendation' => RecommendationOutcome::Recommended->value,
    ]);
});

// ---- The consolidate panel --------------------------------------------------

it('offers the consolidate panel to the HOU once recommendations are in', function () {
    atReview();
    $this->governance->assess(
        request: $this->request->fresh(),
        assessor: $this->reviewer,
        tierId: $this->tier->id,
        classificationId: $this->classification->id,
    );

    foreach ($this->governance->unitsFor($this->classification->id) as $unit) {
        $u = asUser(UserRole::TechnicalReviewer);
        $u->reviewUnits()->syncWithoutDetaching([$unit->id]);

        $this->governance->recordRecommendation(
            request: $this->request->fresh(),
            reviewer: $u->fresh('reviewUnits'),
            unit: $unit,
            outcome: RecommendationOutcome::Recommended,
        );
    }

    $this->actingAs($this->hou);
    $full = GovernanceRoute::where('code', 'full')->first();

    Livewire::test(Show::class, ['request' => $this->request->fresh()])
        ->call('openPanel', 'consolidate')
        ->set('consolidate_route_id', $full->id)
        ->set('consolidate_summary', 'Cross-departmental impact and material spend, so the Full route applies.')
        ->call('consolidate')
        ->assertHasNoErrors();

    expect($this->request->fresh()->current_stage)->toBe(WorkflowStage::CommitteeDecision->value);
});

it('refuses the consolidate panel to a governance reviewer', function () {
    // The reviewer assesses; the HOU consolidates. Different accountability.
    atReview();
    $this->actingAs($this->reviewer);

    Livewire::test(Show::class, ['request' => $this->request->fresh()])
        ->call('openPanel', 'consolidate')
        ->assertForbidden();
});

// ---- The committee panel ----------------------------------------------------

it('offers the committee panel to the secretariat on a Full route', function () {
    atReview();
    $this->governance->assess(
        request: $this->request->fresh(),
        assessor: $this->reviewer,
        tierId: $this->tier->id,
        classificationId: $this->classification->id,
    );

    foreach ($this->governance->unitsFor($this->classification->id) as $unit) {
        $u = asUser(UserRole::TechnicalReviewer);
        $u->reviewUnits()->syncWithoutDetaching([$unit->id]);

        $this->governance->recordRecommendation(
            request: $this->request->fresh(),
            reviewer: $u->fresh('reviewUnits'),
            unit: $unit,
            outcome: RecommendationOutcome::Recommended,
        );
    }

    $full = GovernanceRoute::where('code', 'full')->first();
    $this->governance->consolidate($this->request->fresh(), $this->hou, $full->id, 'Full route applies.');

    $this->actingAs($this->secretary);

    Livewire::test(Show::class, ['request' => $this->request->fresh()])
        ->call('openPanel', 'committee')
        ->set('committee_decision', Decision::Approved->value)
        ->set('committee_comments', 'Approved as presented.')
        ->call('recordCommitteeDecision')
        ->assertHasNoErrors();

    expect($this->request->fresh()->status)->toBe(RequestStatus::Approved->value);
});

it('refuses the committee panel on a route that does not require one', function () {
    /*
     * A decision by a body with no authority over the request would look entirely
     * legitimate on the screen, which is exactly why the panel must not open.
     */
    atReview();
    $this->governance->assess(
        request: $this->request->fresh(),
        assessor: $this->reviewer,
        tierId: $this->tier->id,
        classificationId: $this->classification->id,
    );

    foreach ($this->governance->unitsFor($this->classification->id) as $unit) {
        $u = asUser(UserRole::TechnicalReviewer);
        $u->reviewUnits()->syncWithoutDetaching([$unit->id]);

        $this->governance->recordRecommendation(
            request: $this->request->fresh(),
            reviewer: $u->fresh('reviewUnits'),
            unit: $unit,
            outcome: RecommendationOutcome::Recommended,
        );
    }

    $light = GovernanceRoute::where('code', 'light')->first();
    $this->governance->consolidate($this->request->fresh(), $this->hou, $light->id, 'Light route applies.');

    $this->actingAs($this->secretary);

    Livewire::test(Show::class, ['request' => $this->request->fresh()])
        ->call('openPanel', 'committee')
        ->assertForbidden();
});

// ---- The close panel -------------------------------------------------------

it('lists the closure blockers instead of a bare refusal', function () {
    /*
     * BR-006 shown as a checklist. "Cannot close" with no reason is a dead end; each
     * line names something somebody can go and do.
     */
    atReview();
    $this->actingAs($this->reviewer);

    Livewire::test(Show::class, ['request' => $this->request->fresh()])
        ->call('openPanel', 'close')
        ->assertSee('Closure is blocked')
        ->assertSee('outstanding');
});

it('refuses closure through the panel while a decision is outstanding', function () {
    atReview();
    $this->actingAs($this->reviewer);

    Livewire::test(Show::class, ['request' => $this->request->fresh()])
        ->call('openPanel', 'close')
        ->call('closeRequest')
        ->assertHasErrors(['close']);
});

it('refuses the close panel to a requestor', function () {
    atReview();
    $this->actingAs($this->requestor);

    Livewire::test(Show::class, ['request' => $this->request->fresh()])
        ->call('openPanel', 'close')
        ->assertForbidden();
});

// ---- The workspace ---------------------------------------------------------

it('shows the governance workspace to a reviewer', function () {
    atReview();
    $this->actingAs($this->reviewer);

    Livewire::test(Index::class)
        ->assertOk()
        ->assertSee('Completeness review')
        ->assertSee('Warehouse management system');
});

it('counts each stage from the same set the list is drawn from', function () {
    // A tab that says "3" and shows nothing is the defect this avoids.
    atReview();
    $this->actingAs($this->reviewer);

    $component = Livewire::test(Index::class);

    expect($component->viewData('counts')['completeness_review'])->toBe(1)
        ->and($component->viewData('requests'))->toHaveCount(1);
});

it('excludes closed requests from the awaiting-closure tab', function () {
    /*
     * `close()` leaves `current_stage` at closure, so without a status filter this
     * tab fills with everything ever closed — the opposite of a work queue.
     */
    $this->request->forceFill([
        'current_stage' => WorkflowStage::Closure->value,
        'status' => RequestStatus::Closed->value,
    ])->save();

    $this->actingAs($this->reviewer);

    Livewire::test(Index::class)
        ->call('showStage', 'closure')
        ->assertSee('Nothing is at Awaiting closure');
});

it('ignores an unknown stage rather than erroring', function () {
    $this->actingAs($this->reviewer);

    Livewire::test(Index::class)
        ->call('showStage', 'not_a_stage')
        ->assertSet('stage', 'completeness_review');
});

it('renders recommendation labels, not raw enum values', function () {
    /*
     * The column holds the enum VALUE. Reading the raw attribute printed
     * "recommended_with_conditions" and "not_recommended" on screen — accurate, and
     * not something to show a committee.
     */
    atReview();
    $this->governance->assess(
        request: $this->request->fresh(),
        assessor: $this->reviewer,
        tierId: $this->tier->id,
        classificationId: $this->classification->id,
    );

    $units = $this->governance->unitsFor($this->classification->id);
    $u = asUser(UserRole::TechnicalReviewer);
    $u->reviewUnits()->syncWithoutDetaching([$units[0]->id]);

    $this->governance->recordRecommendation(
        request: $this->request->fresh(),
        reviewer: $u->fresh('reviewUnits'),
        unit: $units[0],
        outcome: RecommendationOutcome::RecommendedWithConditions,
        conditions: 'After the platform migration.',
    );

    $this->actingAs($this->reviewer);

    Livewire::test(Show::class, ['request' => $this->request->fresh()])
        ->assertSee('Recommended with conditions')
        ->assertDontSee('recommended_with_conditions');
});
