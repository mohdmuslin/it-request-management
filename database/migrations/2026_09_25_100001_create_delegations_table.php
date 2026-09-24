<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delegation (FR-014).
 *
 * WHY A TABLE AND NOT A COLUMN
 *
 * `approval_tasks.delegated_from_id` records that a decision was made on someone
 * else's behalf — but it is written *after* the fact. Nothing in the schema could
 * say "Siti is on leave until Friday, so Tan decides for her" *before* the decision
 * happens, which is the point of the feature. Without this table the queue cannot
 * show a delegate what is waiting on them, so they only find out by being asked.
 *
 * WHY REVOCATION IS A TIMESTAMP AND NOT A DELETE
 *
 * A delegation is cited by `approval_tasks.delegated_from_id` on every decision made
 * under it. Deleting the row would leave those decisions pointing at nothing, and
 * BR-008 requires the acting and original approvers to be explainable — which means
 * the arrangement that authorised the act has to survive it.
 *
 * Revoking stops the delegation taking effect; it does not erase it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delegations', function (Blueprint $table) {
            $table->id();

            /*
             * Who is away, and who acts for them.
             *
             * Restrict rather than cascade on delete: a user who has delegations
             * cannot simply be removed, because doing so would silently change who
             * was authorised to decide — and those decisions are already recorded.
             */
            $table->foreignId('approver_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('delegate_id')->constrained('users')->restrictOnDelete();

            /*
             * Who created it.
             *
             * Nullable so a delegation can be set up by the system, but normally the
             * approver themselves. Recorded because "an approver authorised their own
             * substitute" and "an administrator arranged cover" are different, and
             * the difference matters when a decision is disputed.
             */
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            /*
             * The active window.
             *
             * Both required. An end date is the thing that makes this temporary, and
             * a delegation without one is a permanent transfer of authority that
             * nobody will remember to undo — which is how it became unused in the
             * current process in the first place.
             */
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');

            $table->text('reason')->nullable();

            // Revocation. See the class comment: this never deletes.
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            /*
             * The two queries actually made: "is this approver away?" when creating a
             * task, and "who am I acting for?" when building the queue.
             */
            $table->index(['approver_id', 'starts_at', 'ends_at']);
            $table->index(['delegate_id', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delegations');
    }
};
