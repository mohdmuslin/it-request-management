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
