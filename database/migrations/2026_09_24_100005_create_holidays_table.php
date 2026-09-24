<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The holiday calendar.
 *
 * WHY THIS TABLE HAS TO EXIST FOR DUE DATES TO MEAN ANYTHING
 *
 * A due date is counted in business days. Without holidays, the calculation treats
 * every weekday as a working day, so a target that falls across a public holiday
 * silently expires while nobody is at work — and the escalation it triggers is for
 * something nobody could have acted on.
 *
 * WHY THE UNIQUE KEY IS THE DATE ALONE
 *
 * A date is either open or closed. Two holidays on one date is a data error, not
 * a legitimate state, so the narrowest correct key is `date`. Provenance lives in
 * `source`, not in the key — otherwise the same holiday arriving from two sources
 * would be stored twice, and the calendar would double-count it.
 *
 * WHY THERE IS NO `year` COLUMN
 *
 * Malaysian holidays are largely lunar and Islamic, so they cannot be derived from
 * a rule — they are published, and entered by hand each year. A `year` column
 * would suggest the dates are predictable from it. They are not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('name');

            /*
             * Half-days.
             *
             * Null means closed all day. A time means the office closes early — the
             * pattern on the eve of a major festival — and the day contributes only
             * the hours before it. Without this column, every half-day would be
             * treated as either a full working day or a full closure, and both are
             * wrong by half a day.
             */
            $table->time('closes_at')->nullable();

            /*
             * Where the row came from.
             *
             * 'manual' is the baseline and is always available. An organisation
             * with a calendar API can sync instead, and the sync only ever touches
             * rows it owns — a manual entry survives a sync it knows nothing about.
             */
            $table->string('source', 20)->default('manual');

            // The feed's own identifier, so a sync can reconcile an edit rather
            // than delete and recreate the row.
            $table->string('external_id')->nullable();

            /*
             * Shields an administrator's correction from the next sync.
             *
             * If someone fixes a wrongly-sourced holiday, the following sync would
             * otherwise revert it — and the correction would look like it had never
             * been made.
             */
            $table->timestamp('manually_overridden_at')->nullable();

            $table->timestamps();

            $table->index('source');
        });

        /*
         * Sync runs, recorded so a silent failure is visible.
         *
         * WHY THIS LOG EXISTS
         *
         * The sync runs from cron, and cron output on this host goes to /dev/null.
         * A sync that fails therefore fails completely silently, and the only
         * symptom appears weeks later as "the due dates are wrong". Recording the
         * outcome in the database gives it somewhere to be seen.
         */
        Schema::create('holiday_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->string('source', 20);
            $table->string('status', 20);            // success | failed
            $table->unsignedInteger('created')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('skipped_overridden')->default(0);
            $table->unsignedInteger('removed')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('ran_at');
            $table->timestamps();

            $table->index(['source', 'ran_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holiday_sync_logs');
        Schema::dropIfExists('holidays');
    }
};
