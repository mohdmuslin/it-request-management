<?php

use App\Enums\Decision;
use App\Enums\RecommendationOutcome;
use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Enums\WorkflowStage;
use App\Models\Classification;
use App\Models\Department;
use App\Models\GovernanceRoute;
use App\Models\ItRequest;
use App\Models\Recommendation;
use App\Models\ReviewUnit;
use App\Models\Tier;
use App\Models\WorkflowHistory;
use App\Services\GovernanceService;
use App\Services\RequestNumberService;
use App\Services\WorkflowDecisionService;
use App\Services\WorkflowService;
use Database\Seeders\ReferenceDataSeeder;

/**
 * The governance phase (Phase F).
 *
 * Phase F's exit criteria are "a Full-route request reaches and clears a committee
 * decision" and "three units file independent recommendations". The plan also
 * requires the tests to prove PROHIBITED transitions are refused.
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

    $this->department = Department::create(['code' => 'ICT', 'name' => 'Information Technology']);

    $this->tier = Tier::orderBy('sort_order')->first();
    $this->classification = Classification::orderBy('name')->first();

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
});

/** Drive the request through both approvals so it sits at completeness review. */
function atCompletenessReview(): ItRequest
{
    test()->workflow->submit(test()->request);

    test()->actingAs(test()->owner);
    app(WorkflowDecisionService::class)->decide(
        request: test()->request->fresh(),
        actor: test()->owner,
        decision: Decision::Approved,
    );

    test()->actingAs(test()->sponsor);
    app(WorkflowDecisionService::class)->decide(
        request: test()->request->fresh(),
        actor: test()->sponsor,
        decision: Decision::Approved,
    );

    return test()->request->fresh();
}

/** Assess it so it sits at technical recommendation. */
function atTechnicalRecommendation(): ItRequest
{
    $request = atCompletenessReview();

    test()->governance->assess(
        request: $request,
        assessor: test()->reviewer,
        tierId: test()->tier->id,
        classificationId: test()->classification->id,
    );

    return test()->request->fresh();
}

/** A user who belongs to a given unit. */
function reviewerIn(ReviewUnit $unit)
{
    $user = asUser(UserRole::TechnicalReviewer);
    $user->reviewUnits()->syncWithoutDetaching([$unit->id]);

    return $user->fresh('reviewUnits');
}

/** Every assigned unit files a plain recommendation, so the request reaches consolidation. */
function fileAllRecommendations(ItRequest $request): void
{
    $units = app(GovernanceService::class)->unitsFor((int) $request->classification_id);

    foreach ($units as $unit) {
        app(GovernanceService::class)->recordRecommendation(
            request: $request->fresh(),
            reviewer: reviewerIn($unit),
            unit: $unit,
            outcome: RecommendationOutcome::Recommended,
        );
    }
}

// ---- Completeness review ----------------------------------------------------

it('assesses a request and moves it to technical recommendation', function () {
    $request = atCompletenessReview();

    expect($request->current_stage)->toBe(WorkflowStage::CompletenessReview->value);

    $this->governance->assess(
        request: $request,
        assessor: $this->reviewer,
        tierId: $this->tier->id,
        classificationId: $this->classification->id,
        notes: 'Complete.',
    );

    $request->refresh();

    expect($request->current_stage)->toBe(WorkflowStage::TechnicalRecommendation->value)
        ->and($request->status)->toBe(RequestStatus::PendingTechnicalRecommendation->value)
        ->and($request->tier_id)->toBe($this->tier->id)
        ->and($request->classification_id)->toBe($this->classification->id)
        ->and($request->completenessAssessment)->not->toBeNull();
});

it('keeps the requestor\'s proposal when governance confirms the same values', function () {
    // Both values stored, so the audit trail shows that a value was CONFIRMED rather
    // than silently accepted.
    $request = atCompletenessReview();
    $request->forceFill([
        'proposed_tier_id' => $this->tier->id,
        'proposed_classification_id' => $this->classification->id,
    ])->save();

    $this->governance->assess($request->fresh(), $this->reviewer, $this->tier->id, $this->classification->id);

    $assessed = $this->request->fresh();

    expect($assessed->proposed_tier_id)->toBe($this->tier->id)
        ->and($assessed->tier_id)->toBe($this->tier->id)
        ->and($assessed->completenessAssessment->reclassification_reason)->toBeNull();
});

it('requires a reason when governance changes the proposal', function () {
    /*
     * A silent reclassification is the thing a requestor will dispute. Requiring the
     * reason only when something CHANGES means the field carries information exactly
     * when it matters — requiring it every time trains people to type ".".
     */
    $otherTier = Tier::orderByDesc('sort_order')->first();
    $request = atCompletenessReview();
    $request->forceFill(['proposed_tier_id' => $otherTier->id])->save();

    expect(fn () => $this->governance->assess(
        request: $request->fresh(),
        assessor: $this->reviewer,
        tierId: $this->tier->id,
        classificationId: $this->classification->id,
    ))->toThrow(RuntimeException::class, 'changes the tier or classification');
});

it('records the reason when governance changes the proposal', function () {
    $otherTier = Tier::orderByDesc('sort_order')->first();
    $request = atCompletenessReview();
    $request->forceFill(['proposed_tier_id' => $otherTier->id])->save();

    $this->governance->assess(
        request: $request->fresh(),
        assessor: $this->reviewer,
        tierId: $this->tier->id,
        classificationId: $this->classification->id,
        reclassificationReason: 'Spend exceeds the Tier 1 threshold.',
    );

    expect($this->request->fresh()->completenessAssessment->reclassification_reason)
        ->toContain('Tier 1 threshold');
});

it('refuses to assess a request that is not at completeness review', function () {
    // Still a draft — the assessment stage has not been reached.
    expect(fn () => $this->governance->assess(
        request: $this->request,
        assessor: $this->reviewer,
        tierId: $this->tier->id,
        classificationId: $this->classification->id,
    ))->toThrow(RuntimeException::class, 'Cannot assess');
});

// ---- Recommendations (FR-008, BR-005) ---------------------------------------

it('lets three units file independently', function () {
    /*
     * PHASE F EXIT CRITERION.
     *
     * Each unit files its own position. Nothing overwrites anything.
     */
    $request = atTechnicalRecommendation();
    $units = $this->governance->unitsFor($this->classification->id);

    expect($units)->toHaveCount(3);

    foreach ($units as $unit) {
        $reviewer = reviewerIn($unit);

        $this->governance->recordRecommendation(
            request: $request->fresh(),
            reviewer: $reviewer,
            unit: $unit,
            outcome: RecommendationOutcome::Recommended,
        );
    }

    $current = Recommendation::forRequest($request->id)->current()->get();

    expect($current)->toHaveCount(3)
        ->and($current->pluck('review_unit_id')->unique())->toHaveCount(3);
});

it('does not advance until every assigned unit has filed', function () {
    /*
     * THE PARALLEL-WORK REQUIREMENT. The request advances only when every unit is in,
     * so a single slow unit cannot hold it — and the tests prove it does NOT advance
     * early, which is the failure that would go unnoticed.
     */
    $request = atTechnicalRecommendation();
    $units = $this->governance->unitsFor($this->classification->id);

    $first = reviewerIn($units[0]);

    $this->governance->recordRecommendation(
        request: $request->fresh(),
        reviewer: $first,
        unit: $units[0],
        outcome: RecommendationOutcome::Recommended,
    );

    expect($this->request->fresh()->current_stage)->toBe(WorkflowStage::TechnicalRecommendation->value)
        ->and($this->governance->outstandingUnits($this->request->fresh()))->toHaveCount(2);
});

it('advances once the last unit files', function () {
    $request = atTechnicalRecommendation();
    $units = $this->governance->unitsFor($this->classification->id);

    foreach ($units as $unit) {
        $this->governance->recordRecommendation(
            request: $request->fresh(),
            reviewer: reviewerIn($unit),
            unit: $unit,
            outcome: RecommendationOutcome::Recommended,
        );
    }

    expect($this->request->fresh()->current_stage)->toBe(WorkflowStage::Consolidation->value)
        ->and($this->request->fresh()->status)->toBe(RequestStatus::PendingConsolidation->value);
});

it('versions a revision rather than overwriting (BR-005)', function () {
    /*
     * BR-005. A unit that moved from "not recommended" to "recommended with
     * conditions" has told the committee something important by doing so, and the
     * earlier position must survive.
     */
    $request = atTechnicalRecommendation();
    $unit = $this->governance->unitsFor($this->classification->id)[0];
    $reviewer = reviewerIn($unit);

    $this->governance->recordRecommendation(
        request: $request->fresh(),
        reviewer: $reviewer,
        unit: $unit,
        outcome: RecommendationOutcome::NotRecommended,
        evidence: 'No capacity to support this in the current quarter.',
    );

    $this->governance->recordRecommendation(
        request: $request->fresh(),
        reviewer: $reviewer,
        unit: $unit,
        outcome: RecommendationOutcome::RecommendedWithConditions,
        conditions: 'Only if delivery is scheduled after the platform migration.',
    );

    $all = Recommendation::forRequest($request->id)->where('review_unit_id', $unit->id)->get();

    expect($all)->toHaveCount(2);

    $first = $all->firstWhere('version_no', 1);
    $second = $all->firstWhere('version_no', 2);

    // The earlier position survived, with its reason.
    expect($first->recommendation)->toBe(RecommendationOutcome::NotRecommended->value)
        ->and($first->evidence)->toContain('No capacity')
        ->and($second->version_no)->toBe(2)
        ->and($second->supersedes_id)->toBe($first->id);

    // Only the latest is current.
    expect($first->fresh()->isCurrent())->toBeFalse()
        ->and($second->fresh()->isCurrent())->toBeTrue();
});

it('refuses a recommendation from someone outside the unit', function () {
    /*
     * A PROHIBITED action, and an important one: without this check a single reviewer
     * could file all three units' positions, producing a consolidation that looked
     * unanimous while only one unit had actually looked at the request.
     */
    $request = atTechnicalRecommendation();
    $unit = $this->governance->unitsFor($this->classification->id)[0];

    $outsider = asUser(UserRole::TechnicalReviewer);   // holds the role, in no unit

    expect(fn () => $this->governance->recordRecommendation(
        request: $request->fresh(),
        reviewer: $outsider,
        unit: $unit,
        outcome: RecommendationOutcome::Recommended,
    ))->toThrow(RuntimeException::class, 'not a member');
});

it('requires conditions for a conditional recommendation', function () {
    $request = atTechnicalRecommendation();
    $unit = $this->governance->unitsFor($this->classification->id)[0];

    expect(fn () => $this->governance->recordRecommendation(
        request: $request->fresh(),
        reviewer: reviewerIn($unit),
        unit: $unit,
        outcome: RecommendationOutcome::RecommendedWithConditions,
        conditions: null,
    ))->toThrow(RuntimeException::class, 'must record the conditions');
});

it('requires a reason for advising against', function () {
    $request = atTechnicalRecommendation();
    $unit = $this->governance->unitsFor($this->classification->id)[0];

    expect(fn () => $this->governance->recordRecommendation(
        request: $request->fresh(),
        reviewer: reviewerIn($unit),
        unit: $unit,
        outcome: RecommendationOutcome::NotRecommended,
        evidence: null,
    ))->toThrow(RuntimeException::class, 'requires the reason');
});

it('refuses a recommendation when the request is not at the technical stage', function () {
    $unit = $this->governance->unitsFor($this->classification->id)[0];

    expect(fn () => $this->governance->recordRecommendation(
        request: $this->request,
        reviewer: reviewerIn($unit),
        unit: $unit,
        outcome: RecommendationOutcome::Recommended,
    ))->toThrow(RuntimeException::class, 'Cannot record a recommendation');
});

// ---- Consolidation ----------------------------------------------------------

it('routes a Light request past the committee and to closure', function () {
    $request = atTechnicalRecommendation();
    fileAllRecommendations($request);

    $light = GovernanceRoute::where('code', 'light')->first();

    $this->governance->consolidate(
        request: $this->request->fresh(),
        hou: $this->hou,
        governanceRouteId: $light->id,
        summary: 'Low cost, no integration, within the existing platform.',
    );

    $consolidated = $this->request->fresh();

    expect($consolidated->governance_route_id)->toBe($light->id)
        ->and($consolidated->current_stage)->toBe(WorkflowStage::Closure->value)
        ->and($consolidated->status)->toBe(RequestStatus::Approved->value)
        // NOT closed: an approval is not a closure, and closing here would skip the
        // BR-006 documentation check.
        ->and($consolidated->closed_at)->toBeNull();
});

it('routes a Full request to the committee', function () {
    // PHASE F EXIT CRITERION, part one.
    $request = atTechnicalRecommendation();
    fileAllRecommendations($request);

    $full = GovernanceRoute::where('code', 'full')->first();

    $this->governance->consolidate(
        request: $this->request->fresh(),
        hou: $this->hou,
        governanceRouteId: $full->id,
        summary: 'Cross-department, material spend, requires committee oversight.',
    );

    $consolidated = $this->request->fresh();

    expect($consolidated->current_stage)->toBe(WorkflowStage::CommitteeDecision->value)
        ->and($consolidated->status)->toBe(RequestStatus::PendingCommitteeDecision->value)
        ->and($consolidated->pendingApprovalTask)->not->toBeNull();
});

it('stores the route on both the request and the consolidation', function () {
    // So a later question about why a request did or did not reach the committee has
    // an answer even if the request row changes.
    $request = atTechnicalRecommendation();
    fileAllRecommendations($request);

    $full = GovernanceRoute::where('code', 'full')->first();

    $this->governance->consolidate($this->request->fresh(), $this->hou, $full->id, 'Full route: material spend.');

    $consolidation = $this->request->fresh()->consolidation;

    expect($consolidation->governance_route_id)->toBe($full->id)
        ->and($consolidation->summary)->toContain('material spend')
        ->and($consolidation->consolidated_by)->toBe($this->hou->id);
});

it('refuses to consolidate before the recommendations are in', function () {
    $request = atTechnicalRecommendation();
    $light = GovernanceRoute::where('code', 'light')->first();

    expect(fn () => $this->governance->consolidate(
        request: $request->fresh(),
        hou: $this->hou,
        governanceRouteId: $light->id,
        summary: 'Too early.',
    ))->toThrow(RuntimeException::class, 'Cannot consolidate');
});

// ---- Committee --------------------------------------------------------------

it('clears a Full request through a committee decision', function () {
    // PHASE F EXIT CRITERION, part two.
    $request = atTechnicalRecommendation();
    fileAllRecommendations($request);

    $full = GovernanceRoute::where('code', 'full')->first();
    $this->governance->consolidate($this->request->fresh(), $this->hou, $full->id, 'Full route: material spend.');

    $this->governance->recordCommitteeDecision(
        request: $this->request->fresh(),
        recorder: $this->secretary,
        decision: Decision::Approved,
        comments: 'Approved as presented.',
    );

    $decided = $this->request->fresh();

    expect($decided->status)->toBe(RequestStatus::Approved->value)
        ->and($decided->current_stage)->toBe(WorkflowStage::Closure->value)
        ->and($decided->committeeDecision)->not->toBeNull()
        ->and($decided->committeeDecision->decision)->toBe(Decision::Approved);
});

it('refuses a committee decision on a route that does not require one', function () {
    /*
     * A decision by a body with no authority over the request would look entirely
     * legitimate on the screen — which is exactly why it must be refused.
     */
    $request = atTechnicalRecommendation();
    fileAllRecommendations($request);

    $light = GovernanceRoute::where('code', 'light')->first();
    $this->governance->consolidate($this->request->fresh(), $this->hou, $light->id, 'Light route.');

    expect(fn () => $this->governance->recordCommitteeDecision(
        request: $this->request->fresh(),
        recorder: $this->secretary,
        decision: Decision::Approved,
    ))->toThrow(RuntimeException::class, 'does not require a committee');
});

it('requires conditions for a conditional committee approval', function () {
    $request = atTechnicalRecommendation();
    fileAllRecommendations($request);

    $full = GovernanceRoute::where('code', 'full')->first();
    $this->governance->consolidate($this->request->fresh(), $this->hou, $full->id, 'Full route: material spend.');

    expect(fn () => $this->governance->recordCommitteeDecision(
        request: $this->request->fresh(),
        recorder: $this->secretary,
        decision: Decision::ApprovedWithConditions,
        conditions: null,
    ))->toThrow(RuntimeException::class, 'requires the conditions');
});

it('requires a comment when the committee rejects', function () {
    $request = atTechnicalRecommendation();
    fileAllRecommendations($request);

    $full = GovernanceRoute::where('code', 'full')->first();
    $this->governance->consolidate($this->request->fresh(), $this->hou, $full->id, 'Full route: material spend.');

    expect(fn () => $this->governance->recordCommitteeDecision(
        request: $this->request->fresh(),
        recorder: $this->secretary,
        decision: Decision::Rejected,
        comments: null,
    ))->toThrow(RuntimeException::class, 'A comment is required');
});

it('ends the request on a committee rejection', function () {
    $request = atTechnicalRecommendation();
    fileAllRecommendations($request);

    $full = GovernanceRoute::where('code', 'full')->first();
    $this->governance->consolidate($this->request->fresh(), $this->hou, $full->id, 'Full route: material spend.');

    $this->governance->recordCommitteeDecision(
        request: $this->request->fresh(),
        recorder: $this->secretary,
        decision: Decision::Rejected,
        comments: 'The investment is not justified at this time.',
    );

    $decided = $this->request->fresh();

    expect($decided->status)->toBe(RequestStatus::NotRecommended->value)
        ->and($decided->closed_at)->not->toBeNull();
});

it('returns a request for amendment from the committee', function () {
    $request = atTechnicalRecommendation();
    fileAllRecommendations($request);

    $full = GovernanceRoute::where('code', 'full')->first();
    $this->governance->consolidate($this->request->fresh(), $this->hou, $full->id, 'Full route: material spend.');

    $this->governance->recordCommitteeDecision(
        request: $this->request->fresh(),
        recorder: $this->secretary,
        decision: Decision::Returned,
        comments: 'Provide a three-year cost breakdown.',
    );

    $returned = $this->request->fresh();

    expect($returned->status)->toBe(RequestStatus::ReturnedForAmendment->value)
        // BR-007: it resumes at the committee, not from the beginning.
        ->and($returned->returned_from_stage)->toBe(WorkflowStage::CommitteeDecision->value);
});

// ---- Closure (BR-006) -------------------------------------------------------

it('closes a Light-route request once nothing is outstanding', function () {
    $request = atTechnicalRecommendation();
    fileAllRecommendations($request);

    $light = GovernanceRoute::where('code', 'light')->first();
    $this->governance->consolidate($this->request->fresh(), $this->hou, $light->id, 'Light route.');

    expect($this->governance->canClose($this->request->fresh()))->toBeTrue();

    $this->governance->close($this->request->fresh(), $this->reviewer);
    $closed = $this->request->fresh();

    expect($closed->status)->toBe(RequestStatus::Closed->value)
        ->and($closed->closed_at)->not->toBeNull();
});

it('refuses closure while recommendations are outstanding', function () {
    // BR-006, and a PROHIBITED transition.
    $request = atTechnicalRecommendation();

    $blockers = $this->governance->closureBlockers($request->fresh());

    expect($blockers)->not->toBeEmpty()
        ->and(implode(' ', $blockers))->toContain('outstanding');

    expect(fn () => $this->governance->close($request->fresh(), $this->reviewer))
        ->toThrow(RuntimeException::class, 'cannot be closed yet');
});

it('reports the committee blocker using the relation, not the column name', function () {
    /*
     * A GUARD AGAINST A SILENT NULL.
     *
     * The column is `governance_route_id`; the RELATION is `governanceRoute()`.
     * Eloquent resolves relations by method name, so `$request->governance_route`
     * returns NULL rather than erroring — and `null?->requires_committee` is null, so
     * the condition is false and the blocker never fires.
     *
     * That happened: a Full-route request could be closed without a committee
     * decision. Nothing failed, because a missing blocker produces an empty list and
     * an empty list means "closure is permitted".
     *
     * This asserts the relation RESOLVES, which is the thing that silently broke.
     */
    $request = atTechnicalRecommendation();
    fileAllRecommendations($request);

    $full = GovernanceRoute::where('code', 'full')->first();
    $this->governance->consolidate($this->request->fresh(), $this->hou, $full->id, 'Full route: material spend.');

    $consolidated = $this->request->fresh();

    // The relation must resolve, or every downstream check reads null.
    expect($consolidated->governanceRoute)->not->toBeNull()
        ->and($consolidated->governanceRoute->requires_committee)->toBeTrue()
        // And the snake_case form must NOT be relied on anywhere.
        ->and($consolidated->governance_route)->toBeNull();
});

it('refuses closure on a Full route without a committee decision', function () {
    $request = atTechnicalRecommendation();
    fileAllRecommendations($request);

    $full = GovernanceRoute::where('code', 'full')->first();
    $this->governance->consolidate($this->request->fresh(), $this->hou, $full->id, 'Full route: material spend.');

    /*
     * Model the state honestly: the request is at closure, but the committee task was
     * never decided and no decision row exists.
     *
     * Both conditions are deliberately present, because a request cannot legitimately
     * reach closure with an undecided committee task — so BOTH blockers are correct.
     * The first version of this test cleared neither and asserted only the committee
     * line, which failed because the outstanding-task line fired first. That was the
     * guard working and the test being wrong.
     */
    $this->request->fresh()->forceFill(['current_stage' => WorkflowStage::Closure->value])->save();

    $blockers = $this->governance->closureBlockers($this->request->fresh());

    expect(implode(' ', $blockers))->toContain('IT Investment Committee');

    // And the undecided task is reported too, not hidden behind the first blocker.
    expect(implode(' ', $blockers))->toContain('still awaiting a decision');
});

it('lists every closure blocker rather than only the first', function () {
    /*
     * Returns the LIST, not a boolean. "Cannot close" with no reason is a dead end;
     * each line names something somebody can go and do.
     */
    $blockers = $this->governance->closureBlockers($this->request);

    expect(count($blockers))->toBeGreaterThan(1);
});

// ---- The trail --------------------------------------------------------------

it('records every governance act in the history', function () {
    $request = atTechnicalRecommendation();
    $units = $this->governance->unitsFor($this->classification->id);

    foreach ($units as $unit) {
        $this->governance->recordRecommendation(
            request: $request->fresh(),
            reviewer: reviewerIn($unit),
            unit: $unit,
            outcome: RecommendationOutcome::Recommended,
        );
    }

    $light = GovernanceRoute::where('code', 'light')->first();
    $this->governance->consolidate($this->request->fresh(), $this->hou, $light->id, 'Light route.');
    $this->governance->close($this->request->fresh(), $this->reviewer);

    $actions = WorkflowHistory::where('request_id', $this->request->id)
        ->pluck('action')->all();

    expect($actions)->toContain('assess')
        ->and($actions)->toContain('recommendation_filed')
        ->and($actions)->toContain('all_recommendations_in')
        ->and($actions)->toContain('consolidate')
        ->and($actions)->toContain('close');
});

it('records a system transition with no actor', function () {
    /*
     * "Every assigned unit has filed" was decided by nobody — the condition became
     * true. A fabricated actor would put a name against a decision nobody made.
     */
    $request = atTechnicalRecommendation();
    fileAllRecommendations($request);

    $entry = WorkflowHistory::where('request_id', $this->request->id)
        ->where('action', 'all_recommendations_in')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->performed_by)->toBeNull();
});
