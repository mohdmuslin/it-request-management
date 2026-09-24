<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recommendations, consolidation, and committee decisions.
 *
 * Three tables because they are three different things written by three different
 * people: a unit's technical view, the IT HOU's synthesis of them, and the
 * committee's decision. Storing them together would make it impossible to tell
 * which of them a given sentence came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * One row per unit per version. Never updated in place.
         *
         * WHY version_no AND NOT AN UPDATE
         *
         * BR-005 requires recommendations to be preserved rather than overwritten.
         * Three units may revise their position as consolidation progresses, and
         * the committee needs the final view *together with* the history that
         * produced it — a unit that moved from "not recommended" to "recommended
         * with conditions" has told the committee something important by doing so.
         *
         * It also means one unit can never overwrite another's work, which FR-008
         * requires and which a single shared recommendation column would guarantee
         * by construction that it did.
         */
        Schema::create('recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('it_requests')->cascadeOnDelete();
            $table->foreignId('review_unit_id')->constrained('review_units')->restrictOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->restrictOnDelete();

            $table->string('recommendation', 40);
            $table->text('conditions')->nullable();
            $table->text('evidence')->nullable();

            $table->unsignedSmallInteger('version_no')->default(1);
            $table->timestamp('submitted_at')->nullable();

            // The version this one replaced, so a revision chain is walkable in
            // both directions.
            $table->foreignId('supersedes_id')->nullable()->constrained('recommendations')->nullOnDelete();

            $table->timestamps();

            $table->index(['request_id', 'review_unit_id', 'version_no'], 'recommendation_version_index');
            $table->index('submitted_at');
        });

        /*
         * The consolidation, where the governance route is set.
         *
         * Kept as its own row rather than only as a column on the request, so the
         * reasoning survives. A route that appears on a request with no explanation
         * invites the question "who decided this, and on what basis?" — and the
         * answer would be nowhere.
         */
        Schema::create('recommendation_consolidations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('it_requests')->cascadeOnDelete();
            $table->foreignId('consolidated_by')->constrained('users')->restrictOnDelete();

            $table->text('summary');
            $table->foreignId('governance_route_id')->constrained('governance_routes')->restrictOnDelete();

            $table->timestamp('consolidated_at');
            $table->timestamps();

            $table->unique('request_id');
        });

        /*
         * The committee decision.
         *
         * Voting, motions and quorum are deliberately absent. The brief's Appendix
         * C lists the committee operating model as an assumption to validate —
         * whether decisions require voting or only recording — so this records a
         * decision and leaves the rest open. That is declared deviation D-4.
         */
        Schema::create('committee_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('it_requests')->cascadeOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();

            $table->string('decision', 30);
            $table->text('conditions')->nullable();
            $table->timestamp('decided_at');

            $table->timestamps();

            $table->unique('request_id');
        });

        /*
         * The transition log.
         *
         * WHY THIS IS SEPARATE FROM audit_logs
         *
         * This answers "how did this request move?" — a business question, read by
         * the status timeline and by a user looking at their own request.
         *
         * audit_logs answers "who changed what value?" — a compliance question, read
         * during a dispute.
         *
         * Merging them makes the timeline either unreadably detailed or the audit
         * incomplete. Worse, they have different retention and immutability needs:
         * history must stay readable forever, and an audit row must be provably
         * untouched.
         */
        Schema::create('workflow_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('it_requests')->cascadeOnDelete();

            $table->string('from_stage', 50)->nullable();
            $table->string('to_stage', 50);
            $table->string('action', 40);

            /*
             * Nullable for system transitions.
             *
             * "All recommendations are in, so this advanced" has no human actor.
             * Forcing a user id would mean inventing one, and a fabricated actor in
             * an audit trail is worse than an honest null.
             */
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->text('remarks')->nullable();

            /*
             * Only created_at, no updated_at.
             *
             * The row is append-only. An updated_at column on an immutable record is
             * an invitation to write one.
             */
            $table->timestamp('created_at')->useCurrent();

            $table->index(['request_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_histories');
        Schema::dropIfExists('committee_decisions');
        Schema::dropIfExists('recommendation_consolidations');
        Schema::dropIfExists('recommendations');
    }
};
