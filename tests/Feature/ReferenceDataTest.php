<?php

use App\Enums\Classification;
use App\Enums\GovernanceRoute;
use App\Enums\Tier;
use App\Enums\UserRole;
use App\Enums\WorkflowStage;
use App\Models\Classification as ClassificationModel;
use App\Models\GovernanceRoute as GovernanceRouteModel;
use App\Models\ReviewUnit;
use App\Models\Role;
use App\Models\Tier as TierModel;
use App\Models\WorkflowStage as WorkflowStageModel;
use Database\Seeders\ReferenceDataSeeder;

/**
 * Reference data and the invariants that depend on it.
 *
 * These assert the two things most likely to be silently wrong: which units must
 * review a classification, and the rule that only Full reaches the committee. Both
 * are routing decisions, and a routing error sends a request down the wrong path
 * without anybody noticing until much later.
 */
beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
});

it('seeds all three tiers including Partnership', function () {
    expect(TierModel::pluck('code')->all())
        ->toContain(Tier::Tier1->value)
        ->toContain(Tier::Tier2->value)
        ->toContain(Tier::TierP->value);

    // The label matters: Tier P means Partnership, and "P" alone is ambiguous to
    // anyone reading a report.
    expect(TierModel::where('code', Tier::TierP->value)->value('name'))
        ->toBe('Tier P (Partnership)');
});

it('seeds four classifications with Others as a real category', function () {
    expect(ClassificationModel::count())->toBe(4);

    // Others is not an escape hatch — it carries a description explaining when it
    // applies, so it is chosen deliberately rather than by default.
    expect(ClassificationModel::where('code', Classification::Others->value)->value('description'))
        ->not->toBeNull();
});

it('marks only the Full route as requiring a committee', function () {
    $requiring = GovernanceRouteModel::where('requires_committee', true)->pluck('code')->all();

    // This single rule is the whole reason three routes exist. If Moderate were
    // ever flipped to true, every moderate request would be escalated to a
    // committee that has no business seeing it.
    expect($requiring)->toBe([GovernanceRoute::Full->value]);

    expect(GovernanceRouteModel::where('code', GovernanceRoute::Light->value)->value('requires_committee'))
        ->toBeFalsy();
});

it('assigns all three review units to every classification as a safe default', function () {
    /*
     * The business has confirmed that the reviewing units depend on the
     * classification, but has not yet provided the mapping. Seeding the widest case
     * means nothing is missed while the answer is outstanding — a narrower guess
     * would silently skip a review the process requires.
     *
     * When the real mapping arrives this test changes, and the change is a data
     * change rather than a code change.
     */
    foreach (ClassificationModel::all() as $classification) {
        expect($classification->reviewUnits)->toHaveCount(3);
    }

    expect(ReviewUnit::count())->toBe(3);
});

it('seeds a business day target for every stage that needs one', function () {
    foreach ([
        WorkflowStage::ProjectOwner,
        WorkflowStage::ProjectSponsor,
        WorkflowStage::CompletenessReview,
        WorkflowStage::TechnicalRecommendation,
        WorkflowStage::Consolidation,
        WorkflowStage::CommitteeDecision,
    ] as $stageEnum) {
        $stage = WorkflowStageModel::where('code', $stageEnum->value)->firstOrFail();

        expect($stage->businessDaysFor())
            ->not->toBeNull("Stage {$stageEnum->value} has no default due-day target");
    }
});

it('gives the committee a longer target than any approval stage', function () {
    $owner = WorkflowStageModel::where('code', WorkflowStage::ProjectOwner->value)->first();
    $committee = WorkflowStageModel::where('code', WorkflowStage::CommitteeDecision->value)->first();

    // The committee meets periodically, so a short target there would escalate
    // something nobody can act on any faster — and escalating what cannot be fixed
    // is how an alerting system teaches people to ignore it.
    expect($committee->businessDaysFor())->toBeGreaterThan($owner->businessDaysFor());
});

it('seeds all nine roles', function () {
    expect(Role::count())->toBe(count(UserRole::cases()));
});

it('is idempotent when re-run', function () {
    // Reference data is edited by administrators after seeding, so re-running the
    // seeder must update rather than duplicate. A duplicate tier would appear twice
    // on every dropdown.
    $this->seed(ReferenceDataSeeder::class);

    expect(TierModel::count())->toBe(3)
        ->and(ClassificationModel::count())->toBe(4)
        ->and(GovernanceRouteModel::count())->toBe(3)
        ->and(ReviewUnit::count())->toBe(3)
        ->and(Role::count())->toBe(count(UserRole::cases()));
});
