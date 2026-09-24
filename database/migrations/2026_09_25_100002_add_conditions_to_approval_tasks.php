<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conditions attached to an "approved with conditions" decision.
 *
 * WHY THIS NEEDS ITS OWN COLUMN
 *
 * `Decision::requiresConditions()` already refuses an approval-with-conditions that
 * carries none, so the rule existed — but there was nowhere to record them. The
 * condition would have lived in the free-text comment, which means the closure
 * screen cannot cite it, nothing can be ticked off, and "are the conditions met?"
 * becomes a question about prose.
 *
 * TEXT rather than VARCHAR because a condition is usually a sentence, and storing it
 * as JSON would make it searchable but no easier to read back to the approver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_tasks', function (Blueprint $table) {
            $table->text('conditions')->nullable()->after('comments');
        });
    }

    public function down(): void
    {
        Schema::table('approval_tasks', function (Blueprint $table) {
            $table->dropColumn('conditions');
        });
    }
};
