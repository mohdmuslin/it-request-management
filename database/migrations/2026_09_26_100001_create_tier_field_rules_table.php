<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which request fields a tier makes required, optional or hidden (BR-003).
 *
 * WHY THIS IS DATA AND NOT CODE
 *
 * BR-003 requires conditional fields to be driven by tier. Written as `match` statements in the
 * form request, the rule would be correct on the day it was written and would need a release
 * every time the business changed its mind about what Tier 2 has to justify — which, on the
 * evidence of the current process, is roughly annually and always at short notice.
 *
 * As rows, an administrator changes it and the next request picks it up.
 *
 * WHY ABSENCE MEANS OPTIONAL
 *
 * There is no row for the common case. A field is optional unless a tier says otherwise, so
 * adding a field to the form needs no rows at all, and the governing decision — the one worth
 * reading — is the only thing stored.
 *
 * The alternative, a row per field per tier, would have this table grow by three every time a
 * field is added, and every one of those rows would say the same nothing.
 *
 * WHY `hidden` IS A SEPARATE VALUE FROM `optional`
 *
 * "Not required" and "not applicable" are different. A field that is optional invites an answer
 * and accepts a blank; one that is hidden does not apply to this tier at all, and leaving a stale
 * value in it from before the tier was changed would put a number in a column that means nothing
 * for that tier. `hidden` rejects a supplied value rather than ignoring it.
 *
 * WHY THERE IS NO `default` COLUMN
 *
 * A tier override is the exception. Resolution walks from the specific tier to a null tier row —
 * the same shape as `stage_due_days`, deliberately, so there is one rule for reading override
 * tables in this application rather than two.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tier_field_rules', function (Blueprint $table) {
            $table->id();

            /*
             * Nullable: a row with no tier is the fallback for every tier.
             *
             * Nulls are not compared as equal in SQL, so the unique index below cannot stop two
             * default rows for the same field. The seeder and the administration screen both
             * use `updateOrCreate` keyed on this pair, and the screen explicitly null-checks
             * before writing — but a direct insert could produce a duplicate, and resolution
             * takes the first. Recorded here so the next reader knows it is known.
             */
            $table->foreignId('tier_id')->nullable()->constrained('tiers')->cascadeOnDelete();

            /*
             * A field name from the governable list in `App\Services\TierFieldRules`.
             *
             * Not a foreign key, because these are property names on a Livewire component rather
             * than rows in a table. The list is enforced in the service, and an unknown name is
             * refused there with an explanation rather than silently governing nothing — which
             * is the failure that would look like the feature not working.
             */
            $table->string('field', 60);

            $table->string('requirement', 16);   // required | optional | hidden

            $table->timestamps();

            $table->unique(['tier_id', 'field']);
            $table->index('field');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tier_field_rules');
    }
};
