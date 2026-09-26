<?php

namespace Database\Seeders;

use App\Enums\Classification as ClassificationEnum;
use App\Enums\GovernanceRoute as GovernanceRouteEnum;
use App\Enums\Tier as TierEnum;
use App\Enums\UserRole;
use App\Enums\WorkflowStage as WorkflowStageEnum;
use App\Models\Classification;
use App\Models\GovernanceRoute;
use App\Models\ReviewUnit;
use App\Models\Role;
use App\Models\StageDueDay;
use App\Models\Tier;
use App\Models\TierFieldRule;
use App\Models\WorkflowStage;
use Illuminate\Database\Seeder;

/**
 * Reference data.
 *
 * Idempotent, so it can be re-run after a configuration change without creating
 * duplicates. Everything here is data an administrator can edit afterwards — this
 * seeder establishes the starting position, not the permanent one.
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedRoles();
        $this->seedTiers();
        $this->seedClassifications();
        $this->seedGovernanceRoutes();
        $this->seedReviewUnits();
        $this->seedWorkflowStages();
        $this->seedStageDueDays();
        $this->seedClassificationReviewUnits();
        $this->seedTierFieldRules();
    }

    /**
     * Which fields each tier makes required (BR-003).
     *
     * THE STARTING POSITION, NOT THE RULE.
     *
     * The brief says conditional fields must be driven by tier and does not say which field for
     * which tier — that is a business decision, and these are a defensible first draft chosen to
     * be visible rather than neutral:
     *
     *   - **Tier 1** — a budget is not demanded and nothing is hidden. A small, well-understood
     *     request should not need a cost code to be filed, and demanding one produces a zero
     *     rather than an honest blank.
     *   - **Tier 2** — the budget becomes required, because a request of this size is approved
     *     against one, and `forecast_resources` too.
     *   - **Tier P** — a partnership arrangement. The budget is required, and
     *     `dependencies_constraints` is HIDDEN because a partnership with another organisation is
     *     governed by the agreement rather than by an internal dependency list.
     *
     * A no-tier default row is seeded for `budget_amount` so the behaviour without a tier is
     * explicit rather than an accident of there being no rows. Everything else with no row is
     * optional — see the migration for why absence means optional.
     *
     * Deliberately NOT seeded: any rule on `business_plan_reference` or `adhoc_justification`.
     * Those are conditionally required by the business-plan branch, and a tier rule that made one
     * optional would let a request be submitted with neither a plan cited nor a reason given.
     * `TierFieldRules::mayRelax()` documents the same exclusion.
     */
    private function seedTierFieldRules(): void
    {
        $tiers = Tier::pluck('id', 'code');

        $rules = [
            // [tier code or null, field, requirement]
            [null, 'budget_amount', TierFieldRule::OPTIONAL],

            ['tier_1', 'budget_amount', TierFieldRule::OPTIONAL],

            ['tier_2', 'budget_amount', TierFieldRule::REQUIRED],
            ['tier_2', 'budget_source', TierFieldRule::REQUIRED],
            ['tier_2', 'forecast_resources', TierFieldRule::REQUIRED],

            ['tier_p', 'budget_amount', TierFieldRule::REQUIRED],
            ['tier_p', 'budget_code', TierFieldRule::REQUIRED],
            ['tier_p', 'dependencies_constraints', TierFieldRule::HIDDEN],
        ];

        foreach ($rules as [$tierCode, $field, $requirement]) {
            /*
             * `updateOrCreate` rather than `create`.
             *
             * This seeder runs on every deploy, and it must not overwrite a rule an administrator
             * changed — the whole point of the rules being data is that the business can edit
             * them. Updating here would silently revert that on the next release, and the change
             * would reappear days later with nobody connecting it to a deploy.
             *
             * So a row that exists is left alone; only a missing one is created.
             */
            TierFieldRule::firstOrCreate(
                [
                    'tier_id' => $tierCode === null ? null : ($tiers[$tierCode] ?? null),
                    'field' => $field,
                ],
                ['requirement' => $requirement],
            );
        }
    }

    private function seedRoles(): void
    {
        foreach (UserRole::cases() as $i => $role) {
            Role::updateOrCreate(
                ['name' => $role->value],
                [
                    'label' => $role->label(),
                    'sort_order' => $i,
                    'description' => $role->isReadOnly()
                        ? 'Read-only access to every record and the audit log.'
                        : null,
                ],
            );
        }
    }

    private function seedTiers(): void
    {
        $descriptions = [
            TierEnum::Tier1->value => 'Standard request. Lightest assessment.',
            TierEnum::Tier2->value => 'Requires fuller assessment.',
            TierEnum::TierP->value => 'Partnership arrangement.',
        ];

        foreach (TierEnum::cases() as $i => $tier) {
            Tier::updateOrCreate(
                ['code' => $tier->value],
                ['name' => $tier->label(), 'description' => $descriptions[$tier->value], 'sort_order' => $i],
            );
        }
    }

    private function seedClassifications(): void
    {
        $descriptions = [
            ClassificationEnum::NewSystem->value => 'A system the organisation does not currently have.',
            ClassificationEnum::Enhancement->value => 'A change to something already in use.',
            ClassificationEnum::SubscriptionLicense->value => 'A recurring subscription or a licence.',
            ClassificationEnum::Others->value => 'Anything that fits none of the above — for example equipment purchased for a specific project.',
        ];

        foreach (ClassificationEnum::cases() as $i => $classification) {
            Classification::updateOrCreate(
                ['code' => $classification->value],
                [
                    'name' => $classification->label(),
                    'description' => $descriptions[$classification->value],
                    'sort_order' => $i,
                ],
            );
        }
    }

    private function seedGovernanceRoutes(): void
    {
        foreach (GovernanceRouteEnum::cases() as $i => $route) {
            GovernanceRoute::updateOrCreate(
                ['code' => $route->value],
                [
                    'name' => $route->label(),
                    'description' => $route->description(),
                    'requires_committee' => $route->requiresCommittee(),
                    'sort_order' => $i,
                ],
            );
        }
    }

    private function seedReviewUnits(): void
    {
        $units = [
            ['code' => 'it_operations', 'name' => 'IT Operations'],
            ['code' => 'it_platforms',  'name' => 'IT Platforms'],
            ['code' => 'it_delivery',   'name' => 'IT Delivery & Governance'],
        ];

        foreach ($units as $i => $unit) {
            ReviewUnit::updateOrCreate(['code' => $unit['code']], $unit + ['sort_order' => $i]);
        }
    }

    private function seedWorkflowStages(): void
    {
        foreach (WorkflowStageEnum::cases() as $stage) {
            WorkflowStage::updateOrCreate(
                ['code' => $stage->value],
                [
                    'name' => $stage->label(),
                    'sort_order' => $stage->order(),
                    'is_approval' => in_array($stage, [
                        WorkflowStageEnum::ProjectOwner,
                        WorkflowStageEnum::ProjectSponsor,
                    ], true),
                    'is_governance' => in_array($stage, [
                        WorkflowStageEnum::CompletenessReview,
                        WorkflowStageEnum::TechnicalRecommendation,
                        WorkflowStageEnum::Consolidation,
                    ], true),
                    'is_committee' => $stage === WorkflowStageEnum::CommitteeDecision,
                ],
            );
        }
    }

    /**
     * The default target per stage, from config.
     *
     * A null tier_id means "applies to every tier". A tier-specific override is an
     * additional row, not a replacement — which is why this seeds only the default
     * and leaves overrides to whoever needs them.
     *
     * THROWS ON AN UNRESOLVABLE KEY RATHER THAN SKIPPING IT.
     *
     * An earlier version skipped silently, and a mismatch between the config keys
     * and the WorkflowStage enum values meant no stage received a due date at all —
     * so every request in the system would have had no deadline, with nothing
     * reporting a problem. A seeder that fails loudly is worth far more than one
     * that always "succeeds".
     */
    private function seedStageDueDays(): void
    {
        foreach (config('itrequest.stage_due_days') as $stageCode => $businessDays) {
            $stage = WorkflowStage::where('code', $stageCode)->first();

            if (! $stage) {
                throw new \RuntimeException(
                    "No workflow stage matches the configured due-day key '{$stageCode}'. "
                    .'The keys in config/itrequest.php stage_due_days must match '
                    .'App\Enums\WorkflowStage values exactly. Valid values: '
                    .implode(', ', array_column(WorkflowStageEnum::cases(), 'value'))
                );
            }

            StageDueDay::updateOrCreate(
                ['workflow_stage_id' => $stage->id, 'tier_id' => null],
                ['business_days' => $businessDays],
            );
        }
    }

    /**
     * Which units review which classification.
     *
     * Seeded as ALL THREE units against EVERY classification.
     *
     * WHY THE BROAD DEFAULT
     *
     * The business has said the reviewing units depend on the classification, but
     * has not yet confirmed the mapping. Seeding the widest case means nothing is
     * missed while the answer is outstanding, and narrowing it later is a data
     * change rather than a code change. A narrower guess would silently skip a
     * review the process requires.
     */
    private function seedClassificationReviewUnits(): void
    {
        $units = ReviewUnit::orderBy('sort_order')->get();

        foreach (Classification::all() as $classification) {
            foreach ($units as $i => $unit) {
                $classification->reviewUnits()->syncWithoutDetaching([
                    $unit->id => ['sort_order' => $i],
                ]);
            }
        }
    }
}
