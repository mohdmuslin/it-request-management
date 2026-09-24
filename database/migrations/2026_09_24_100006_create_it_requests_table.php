<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The request itself.
 *
 * Column names follow the vendor brief's §5.1 schema where the brief names them,
 * and the field catalogue otherwise. Fields whose meaning is inferred rather than
 * confirmed are marked `@EDITME` in the comment.
 *
 * WHY DEPARTMENT AND DIVISION ARE SNAPSHOTS
 *
 * They are copied from the requestor at submission rather than looked up live. If
 * a requestor transfers department, the historical request must still report
 * against the department it was raised in. A live lookup would silently rewrite
 * history, and every past report would change without anybody editing anything.
 *
 * WHY THERE ARE BOTH PROPOSED AND ASSIGNED TIER/CLASSIFICATION COLUMNS
 *
 * The requestor proposes; IT Governance assigns. Keeping both means the audit
 * trail can show that a value changed and what it changed from — which matters
 * when a requestor later disputes a classification.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('it_requests', function (Blueprint $table) {
            $table->id();

            /*
             * System-generated and immutable (FR-005, BR-009).
             *
             * Not user-editable through any request screen, and not mass
             * assignable. In the current SharePoint list this is a hand-typed text
             * field, which is how duplicate identifiers happen.
             */
            $table->string('request_no', 30)->unique();

            $table->string('title');
            $table->date('request_date');

            $table->foreignId('requestor_id')->constrained('users')->restrictOnDelete();

            // Snapshots. See the class comment.
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('division_id')->nullable()->constrained('divisions')->nullOnDelete();

            /*
             * Named per request, in wizard step 1.
             *
             * Deliberately not derived from an org chart. A named approver is
             * accountable and the trail is unambiguous; a role-based queue lets a
             * request sit unowned with nobody able to say whose turn it is.
             *
             * NULLABLE, because a draft is not yet a request.
             *
             * This was NOT NULL, which made a draft impossible to save until the
             * requestor had chosen an owner — so somebody who did not yet know who
             * the owner would be could not start at all, and would keep the idea in
             * a private document instead. That is the behaviour this system exists
             * to replace.
             *
             * The requirement is enforced where it belongs: wizard step 1 requires
             * it, and submission re-validates every step before the request enters
             * the chain. So no request can ever be SUBMITTED without an owner — the
             * constraint is on the transition, not on the row's existence.
             */
            $table->foreignId('project_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('project_sponsor_id')->nullable()->constrained('users')->restrictOnDelete();

            // The requestor's proposal.
            $table->foreignId('proposed_tier_id')->nullable()->constrained('tiers')->nullOnDelete();
            $table->foreignId('proposed_classification_id')->nullable()->constrained('classifications')->nullOnDelete();

            // The values IT Governance assigned. Null until completeness review.
            $table->foreignId('tier_id')->nullable()->constrained('tiers')->nullOnDelete();
            $table->foreignId('classification_id')->nullable()->constrained('classifications')->nullOnDelete();

            // Determined at consolidation, not at submission.
            $table->foreignId('governance_route_id')->nullable()->constrained('governance_routes')->nullOnDelete();

            /*
             * Where the request is, and which stage owns the next action.
             *
             * Stored as strings rather than enum columns so a status can be added
             * without an ALTER on a large table, and so the values stay readable in
             * a raw query. They are validated against the enums on every write.
             */
            $table->string('status', 40)->default('draft');
            $table->string('current_stage', 50)->nullable();

            // ---- Section B: the request details ----------------------------

            $table->text('business_need')->nullable();

            // The conditional group. Which of the two following fields is
            // mandatory depends on this value — BR-003 in concrete form.
            $table->string('business_plan_status', 20)->nullable();
            $table->string('business_plan_reference')->nullable();
            $table->text('adhoc_justification')->nullable();

            /*
             * Money. DECIMAL, never a float.
             *
             * The brief's §5.2 forbids floating-point storage for financial values,
             * and it is right to: binary floating point cannot represent 0.10
             * exactly, so totals drift.
             */
            $table->decimal('budget_amount', 12, 2)->nullable();
            $table->string('budget_source', 100)->nullable();
            $table->string('budget_code', 50)->nullable();
            $table->string('funding_type', 50)->nullable();   // @EDITME option list unknown

            /*
             * Urgency is context, not a clock.
             *
             * Captured and shown to approvers. It deliberately does NOT adjust any
             * due date — the target is a function of the stage, per the business
             * decision. The justification field exists because "urgent" without a
             * reason is an assertion, whereas "urgent — licence expires 31 Oct" is
             * something an approver can evaluate.
             */
            $table->string('urgency', 20)->nullable();
            $table->text('urgency_justification')->nullable();

            $table->text('risk_summary')->nullable();
            $table->text('mitigation_plan')->nullable();
            $table->text('dependencies_constraints')->nullable();
            $table->text('impact_if_not_implemented')->nullable();
            $table->text('value_proposition')->nullable();
            $table->text('in_scope')->nullable();
            $table->text('out_of_scope')->nullable();

            $table->date('proposed_start_date')->nullable();
            $table->date('target_completion_date')->nullable();
            $table->text('forecast_resources')->nullable();

            // ---- Lifecycle timestamps --------------------------------------

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            /*
             * Recorded at closure.
             *
             * The current process ends at an off-page connector labelled "PROCEED
             * NEXT STAGE", so no outcome is captured today and the loop is never
             * closed. BR-006 requires all mandatory decisions before closure; this
             * column is what makes the eventual answer to "what happened to that
             * request?" available from the record.
             */
            $table->string('outcome', 40)->nullable();

            /*
             * The stage that returned the request, if it is currently returned.
             *
             * This is what makes BR-007 possible. A return resumes at the stage
             * that returned it, not at the beginning — so the requestor is not
             * sent back through approvals that already passed, and approvers do not
             * re-read decisions they have already made.
             */
            $table->string('returned_from_stage', 50)->nullable();

            $table->timestamps();

            // Indexes for the queries the application actually makes.
            $table->index('status');
            $table->index('current_stage');
            $table->index('requestor_id');
            $table->index('project_owner_id');
            $table->index('project_sponsor_id');
            $table->index('tier_id');
            $table->index('classification_id');
            $table->index('governance_route_id');
            $table->index('request_date');
            $table->index('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('it_requests');
    }
};
