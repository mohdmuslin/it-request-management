<?php

namespace App\Services;

use App\Models\TierFieldRule;
use Illuminate\Support\Facades\Cache;

/**
 * Which request fields a tier makes required, optional or hidden (BR-003).
 *
 * WHY THIS IS A SERVICE AND NOT A SCOPE ON THE MODEL
 *
 * Three callers need the same answer and must not be able to disagree:
 *
 *   1. the form request, which decides what is valid
 *   2. the wizard view, which decides what is shown and marked
 *   3. the wizard component, which clears fields the tier hides
 *
 * A scope would serve the first two and leave the third to reimplement the resolution — and the
 * version that drifts is always the one nobody is looking at. This is the same reasoning as
 * `ReportingService` having a single query entry point (UAT-014).
 *
 * THE RESOLUTION RULE, IN ONE SENTENCE
 *
 * A tier's own row wins; the null-tier row applies otherwise; no row means optional.
 *
 * WHY THE GOVERNED FIELD LIST IS HERE AND NOT IN THE DATABASE
 *
 * These are property names on the wizard component. A foreign key to a `fields` table would
 * imply fields can be added by an administrator, and they cannot — each one needs a column, a
 * label and a place in a step. Allowing a row naming a field that does not exist would produce a
 * rule that silently governs nothing, which looks identical to the feature being broken.
 *
 * The list is checked on write rather than on read, so the failure lands on the administrator
 * who typed the name instead of on a requestor whose form misbehaves an hour later.
 */
class TierFieldRules
{
    /**
     * Fields an administrator may set a rule for.
     *
     * Deliberately NOT every field on the form. `title`, `department_id` and `project_owner_id`
     * are always required — a request without them is not a request, and offering a rule that
     * could make them optional would allow a configuration that breaks the workflow. `status`,
     * `current_stage` and `request_no` are not on the form at all.
     *
     * `business_plan_reference` and `adhoc_justification` are ALSO absent, and for a different
     * reason: they are a pair governed by whether the request claims alignment with an approved
     * plan. Exactly one is required, and which depends on an earlier answer.
     *
     * They were originally in this list. That was a defect with a quiet symptom: the screen
     * offered them, a tier set to `optional` emitted a `nullable` rule, and because tier rules are
     * merged OVER the step rules it **replaced** the `requiredIf` — so a Tier 1 request could be
     * submitted claiming plan alignment with no plan cited and no reason given. `mayRelax()` was
     * written to describe that rule and was never consulted, so nothing stopped it.
     *
     * Removing them here is the fix. `tierRules()` also skips a non-relaxable field as a second
     * guard, so the two cannot disagree.
     *
     * Grouped by the step they appear in, so the administration screen can present them in the
     * order a requestor meets them.
     *
     * @var array<int, array{step: int, label: string, fields: array<string, string>}>
     */
    public const GOVERNED = [
        [
            'step' => 2,
            'label' => 'Business justification',
            'fields' => [
                'value_proposition' => 'Value proposition',
            ],
        ],
        [
            'step' => 3,
            'label' => 'Budget and timeline',
            'fields' => [
                'budget_amount' => 'Budget amount',
                'budget_source' => 'Budget source',
                'budget_code' => 'Budget code',
                'funding_type' => 'Funding type',
                'proposed_start_date' => 'Proposed start date',
                'target_completion_date' => 'Target completion date',
                'forecast_resources' => 'Forecast resources',
            ],
        ],
        [
            'step' => 4,
            'label' => 'Risk and scope',
            'fields' => [
                'risk_summary' => 'Risk summary',
                'mitigation_plan' => 'Mitigation plan',
                'dependencies_constraints' => 'Dependencies and constraints',
                'in_scope' => 'In scope',
                'out_of_scope' => 'Out of scope',
            ],
        ],
    ];

    /** @var array<int, array<string, string>>|null Per-request cache, keyed by tier. */
    private ?array $resolved = null;

    /**
     * Every governed field name, flat.
     *
     * @return array<int, string>
     */
    public static function fieldNames(): array
    {
        $names = [];

        foreach (self::GOVERNED as $group) {
            $names = array_merge($names, array_keys($group['fields']));
        }

        return $names;
    }

    /** The label for a field, for an error message or the administration screen. */
    public static function label(string $field): string
    {
        foreach (self::GOVERNED as $group) {
            if (isset($group['fields'][$field])) {
                return $group['fields'][$field];
            }
        }

        return $field;
    }

    public static function isGoverned(string $field): bool
    {
        return in_array($field, self::fieldNames(), true);
    }

    /** The step a field belongs to, so a validation failure can name where to go. */
    public static function stepFor(string $field): ?int
    {
        foreach (self::GOVERNED as $group) {
            if (isset($group['fields'][$field])) {
                return $group['step'];
            }
        }

        return null;
    }

    /**
     * The effective requirement for every governed field, for one tier.
     *
     * @return array<string, string> field => required|optional|hidden
     */
    public function forTier(?int $tierId): array
    {
        if ($this->resolved !== null && array_key_exists($tierId ?? 0, $this->resolved)) {
            return $this->resolved[$tierId ?? 0];
        }

        /*
         * Read once and resolved in PHP rather than as two queries with a COALESCE.
         *
         * The table is small — at most three tiers times eighteen fields — and resolving here
         * means the precedence rule is visible in one place instead of encoded in SQL. It also
         * makes the cache key obvious.
         */
        $rows = Cache::remember('tier_field_rules', now()->addMinutes(10), function () {
            return TierFieldRule::query()->get(['tier_id', 'field', 'requirement'])
                ->map(fn ($r) => ['tier_id' => $r->tier_id, 'field' => $r->field, 'requirement' => $r->requirement])
                ->all();
        });

        $effective = [];

        // Start from the defaults, so every governed field has an answer.
        foreach (self::fieldNames() as $field) {
            $effective[$field] = TierFieldRule::OPTIONAL;
        }

        foreach ($rows as $row) {
            if ($row['tier_id'] === null) {
                $effective[$row['field']] = $row['requirement'];
            }
        }

        // Then the tier's own rows, which win.
        if ($tierId !== null) {
            foreach ($rows as $row) {
                if ((int) $row['tier_id'] === $tierId) {
                    $effective[$row['field']] = $row['requirement'];
                }
            }
        }

        return $this->resolved[$tierId ?? 0] = $effective;
    }

    /**
     * Field names that are required for a tier, excluding any hidden by the same tier.
     *
     * @return array<string, string> field => label
     */
    public function requiredFor(?int $tierId): array
    {
        $out = [];

        foreach ($this->forTier($tierId) as $field => $requirement) {
            if ($requirement === TierFieldRule::REQUIRED) {
                $out[$field] = self::label($field);
            }
        }

        return $out;
    }

    /**
     * Field names hidden for a tier.
     *
     * @return array<int, string>
     */
    public function hiddenFor(?int $tierId): array
    {
        return array_keys(array_filter(
            $this->forTier($tierId),
            fn (string $r) => $r === TierFieldRule::HIDDEN,
        ));
    }

    /**
     * Whether a requirement suffices, given what the rule set needs anyway.
     *
     * A field the base rules make conditionally required — the business-plan branch, the
     * urgency justification — must stay required when its condition holds, whatever the tier
     * says. A tier rule can ADD a requirement; it cannot remove one that the workflow depends
     * on, or a Tier 1 request could be submitted with neither a plan reference nor a reason.
     */
    public function mayRelax(string $field): bool
    {
        return ! in_array($field, [
            // Conditionally required by the business-plan branch.
            'business_plan_reference',
            'adhoc_justification',
        ], true);
    }

    /** Forget the cache. Called when a rule is saved, and between tests. */
    public function forget(): void
    {
        $this->resolved = null;
        Cache::forget('tier_field_rules');
    }
}
