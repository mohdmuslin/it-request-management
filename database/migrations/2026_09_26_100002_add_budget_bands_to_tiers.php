<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The budget band that decides a tier (Tier 1 / Tier 2), or the absence of one (Tier P).
 *
 * WHY THE BAND IS STORED RATHER THAN THE RULE BEING CODE
 *
 * The organisation's thresholds are RM50,000 today. They are governance policy, and policy
 * changes at short notice and always mid-cycle — which is the same argument as `stage_due_days`
 * and `tier_field_rules`. Writing `> 50000` into a validation rule would make a threshold change
 * a release.
 *
 * WHY NULL MEANS "NOT BUDGET-BASED"
 *
 * A partnership is not a band. It has no lower bound and no upper bound — a RM20,000 collaboration
 * and a RM2,000,000 one are both Tier P. Modelling that as an open-ended band (`min = 0`) would
 * make it overlap every other tier and turn the overlap check into a permanent warning that
 * nobody reads.
 *
 * So `budget_min` and `budget_max` are both nullable, and a row with neither is a tier that
 * budget does not determine. The resolver returns it only when explicitly asked for by code —
 * never by looking at an amount.
 *
 * WHY `budget_max` IS NULLABLE ON A BANDED TIER
 *
 * An open-ended top band is normal: "Tier 2 is RM50,001 and above" has no ceiling, and inventing
 * one (999,999,999) would be a number somebody eventually has to explain. Null means no upper
 * bound, which is what the business means.
 *
 * THE BOUNDS ARE INCLUSIVE, WHICH IS WHY THE SEEDED BANDS DO NOT TOUCH
 *
 * `budget_min` is inclusive and `budget_max` is inclusive, so RM50,000 belongs to exactly one
 * band only if the neighbouring band starts at RM50,001. The overlap check in `TierBands` refuses
 * a configuration where two bands both contain an amount — because "Tier 1 or Tier 2?" having two
 * answers is worse than either answer being wrong.
 *
 * `decimal(12,2)` matches `it_requests.budget_amount`. Comparing a band held to two decimal
 * places against an amount held to two decimal places means an amount of RM50,000.001 is
 * impossible and cannot fall between the bands.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tiers', function (Blueprint $table) {
            $table->decimal('budget_min', 12, 2)->nullable()->after('description');
            $table->decimal('budget_max', 12, 2)->nullable()->after('budget_min');

            /*
             * Who decides a request's tier, per tier.
             *
             * `requestor` — the requestor picks it from the band the amount falls into, and the
             * application refuses a contradiction.
             * `governance` — the requestor cannot pick it at all; the dashboard, governance
             * screen and API set it.
             *
             * Stored per tier rather than as one global setting, because the two coexist: Tier 1
             * and Tier 2 are budget-banded and chosen by cost, while Tier P is a judgement
             * governance makes. A single setting would force one of them to be modelled wrongly.
             */
            $table->string('assignable_by', 20)->default('requestor')->after('budget_max');

            $table->index(['budget_min', 'budget_max']);
        });

        $this->backfillExistingTiers();
    }

    /**
     * Give the tiers that already exist their bands.
     *
     * WHY THIS IS HERE AND NOT IN THE SEEDER, AND WHY IT IS NOT OPTIONAL
     *
     * The seeder creates a band for a tier that is missing and *deliberately does not overwrite*
     * one that exists — because the thresholds are governance policy, and a release that reverted
     * an administrator's change would be worse than one that failed loudly.
     *
     * That care has a consequence on an EXISTING install: the three tiers are already there, so
     * the seeder touches none of them, the new columns stay null, and **no tier is budget-based**.
     * Every request would then be refused with "no tier covers this budget" — an application that
     * appears to have lost the ability to accept anything, from a deploy that reported success.
     *
     * This project has produced exactly this shape of bug before: a new column added to a table
     * that already had rows, with the seeding that only ever runs on a fresh database. A MIGRATION
     * is the right place, because it runs once, on the database that has the rows, and cannot
     * revert a later change the way a re-run seeder could.
     *
     * `whereNull` on BOTH bounds is the condition, not "the column is new" — a tier with any bound
     * set has been configured, and a tier with none is either new or Tier P. Tier P is separated
     * by its code, which is the only handle available: `assignable_by` has a column default, so
     * there is no way to tell "never set" from "deliberately set to requestor" for it.
     */
    private function backfillExistingTiers(): void
    {
        $bands = [
            'tier_1' => ['0.00', '50000.00', 'requestor'],
            'tier_2' => ['50000.01', null, 'requestor'],
        ];

        foreach ($bands as $code => [$min, $max, $assignableBy]) {
            DB::table('tiers')
                ->where('code', $code)
                ->whereNull('budget_min')
                ->whereNull('budget_max')
                ->update([
                    'budget_min' => $min,
                    'budget_max' => $max,
                    'assignable_by' => $assignableBy,
                ]);
        }

        /*
         * Tier P is not a band and is not the requestor's to choose.
         *
         * Its bounds stay null — a partnership has no lower or upper limit — and `assignable_by`
         * is set here because the column default cannot express it. Guarded on the code alone,
         * since a null band IS the correct state for this tier and so cannot be used to detect
         * anything.
         */
        DB::table('tiers')
            ->where('code', 'tier_p')
            ->update(['assignable_by' => 'governance']);
    }

    public function down(): void
    {
        Schema::table('tiers', function (Blueprint $table) {
            $table->dropIndex(['budget_min', 'budget_max']);
            $table->dropColumn(['budget_min', 'budget_max', 'assignable_by']);
        });
    }
};
