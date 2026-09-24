<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workflow stages and their due dates.
 *
 * Two tables, not one. `workflow_stages` is the process definition — the eight
 * steps a request passes through. `stage_due_days` is policy layered on top: how
 * long each step is allowed, optionally overridden per tier.
 *
 * WHY THEY ARE SEPARATE
 *
 * A stage exists whether or not it has a due date. Merging them would mean a
 * stage cannot be defined until somebody has agreed its target, which is exactly
 * the position this project started in — no due dates anywhere in the current
 * process.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_stages', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();     // matches a WorkflowStage value
            $table->string('name');
            $table->unsignedSmallInteger('sort_order');
            $table->boolean('is_approval')->default(false);
            $table->boolean('is_governance')->default(false);

            /*
             * Only the committee stage sets this true.
             *
             * It mirrors governance_routes.requires_committee from the other
             * direction: that column says which routes need a committee, this says
             * which stage is the committee. Both are needed because the request
             * asks the first question and the timeline asks the second.
             */
            $table->boolean('is_committee')->default(false);

            $table->timestamps();
        });

        Schema::create('stage_due_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_stage_id')->constrained()->cascadeOnDelete();

            /*
             * Nullable tier, and that is the point.
             *
             * A null tier_id is the DEFAULT for every tier. A non-null row overrides
             * it for that tier alone. So the common case needs one row per stage,
             * and a tier-specific target is an addition rather than a rewrite.
             */
            $table->foreignId('tier_id')->nullable()->constrained()->cascadeOnDelete();

            /*
             * Business days, not calendar days.
             *
             * "Three days" means three working days on the business calendar —
             * weekdays, minus the lunch break, minus holidays. A calendar-day
             * target would expire over a weekend while nobody was at work, which
             * is how a due-date system generates complaints.
             */
            $table->unsignedSmallInteger('business_days');

            $table->timestamps();

            /*
             * One target per stage per tier.
             *
             * MySQL treats NULLs as distinct in a unique index, so this does NOT
             * by itself prevent two default rows for the same stage. The seeder
             * enforces the single default, and a test asserts it.
             */
            $table->unique(['workflow_stage_id', 'tier_id'], 'stage_due_day_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_due_days');
        Schema::dropIfExists('workflow_stages');
    }
};
