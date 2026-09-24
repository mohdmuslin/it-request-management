<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Approval tasks — one row per approver per stage.
 *
 * WHY THIS IS A TABLE OF TASKS AND NOT AN approver_id COLUMN ON THE REQUEST
 *
 * The approval is a chain: Project Owner, then Project Sponsor, then governance,
 * then possibly a committee. A single column can hold only the current approver,
 * so it loses the history that makes the trail auditable — and a chain cannot be
 * retrofitted onto it without a rewrite.
 *
 * Every decision is a row. Nothing is overwritten, so "who approved this, when,
 * and what did they say?" is answerable for the whole life of the request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('it_requests')->cascadeOnDelete();

            $table->string('stage', 50);
            $table->unsignedSmallInteger('sequence');

            /*
             * Nullable, because an unassigned task is a real state.
             *
             * Project Owner and Sponsor stages always have a named approver — they are
             * named on the request. The governance, technical, consolidation and
             * committee stages are held by ROLE, and this resolves the first active
             * holder of that role.
             *
             * When nobody holds the role, the honest answer is no assignee. A
             * non-nullable column would force a fabricated one, and the obvious
             * candidate — the requestor — would produce the requestor approving their
             * own governance review, which looks like it is working and is not.
             */
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();

            /*
             * Delegation (FR-014).
             *
             * NULL when the named approver acted themselves. Set to the person who
             * was unavailable when a delegate acted on their behalf.
             *
             * WHY BOTH COLUMNS EXIST
             *
             * Recording only the substitute loses who the decision belonged to;
             * recording only the original loses who actually clicked. BR-008
             * requires both, and this is the single most valuable field in the
             * schema for the current process — the flow has no delegation at all,
             * so an approver on leave stops every request waiting on them.
             */
            $table->foreignId('delegated_from_id')->nullable()->constrained('users')->nullOnDelete();

            /*
             * Computed on entry, then stored.
             *
             * Not recomputed on read. A stored due date makes "what is overdue?" an
             * indexed comparison rather than business-time arithmetic over every
             * open request — and on shared hosting with no worker, that difference
             * is visible on every page load.
             */
            $table->timestamp('due_at')->nullable();

            $table->string('decision', 20)->nullable();
            $table->text('comments')->nullable();
            $table->timestamp('decided_at')->nullable();

            /*
             * Breach and reminder flags, set once and never cleared by a recompute.
             *
             * WHY THIS MATTERS
             *
             * Adding a holiday changes what the correct due date would have been for
             * open requests, so those due dates are recomputed. A recompute can move
             * a due date *earlier*, which would make an already-sent breach alert
             * fire a second time. These columns make each alert send exactly once.
             */
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('breached_at')->nullable();

            $table->timestamps();

            /*
             * One pending task per request.
             *
             * Enforced in the service layer and asserted by a test. Two pending
             * tasks would let two people decide the same stage, and the second
             * would silently overwrite the first.
             */
            $table->index(['request_id', 'decided_at']);
            $table->index(['approver_id', 'decided_at']);
            $table->index('decision');
            $table->index('due_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_tasks');
    }
};
