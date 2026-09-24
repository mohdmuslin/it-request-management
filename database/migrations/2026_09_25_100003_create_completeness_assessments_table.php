<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Completeness review — the governance stage that assesses a request.
 *
 * WHY THIS IS ITS OWN TABLE AND NOT COLUMNS ON THE REQUEST
 *
 * The tier and classification the requestor PROPOSED are already on `it_requests`,
 * and so are the ones governance ASSIGNS. What is missing is the record of the act:
 * who assessed it, when, and what they said. A column pair with no record means the
 * question "why was this classified as Tier 2 when the requestor proposed Tier 1?"
 * has no answer — and that is exactly the question a requestor asks.
 *
 * WHY THE ASSESSMENT IS SEPARATE FROM THE RECOMMENDATION ROWS
 *
 * The assessment is one decision by one reviewer. The recommendations are three
 * independent opinions filed in parallel afterwards. Merging them would make the
 * reviewer look like a fourth recommending unit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('completeness_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('it_requests')->cascadeOnDelete();

            /*
             * Restrict rather than cascade: an assessment is a governance record and
             * must not disappear because a user row was removed. The requestor of an
             * assessment is accountable for it.
             */
            $table->foreignId('assessed_by')->constrained('users')->restrictOnDelete();

            /*
             * What governance confirmed, which may differ from what was proposed.
             *
             * Nullable because classification may not be settled at the moment of
             * assessment — but a request cannot LEAVE this stage without both set,
             * which is enforced in the service rather than by a column constraint, so
             * the reason can be explained to the user instead of surfacing as a
             * database error.
             */
            $table->foreignId('tier_id')->nullable()->constrained('tiers')->nullOnDelete();
            $table->foreignId('classification_id')->nullable()->constrained('classifications')->nullOnDelete();

            /*
             * Why governance disagreed with the requestor, where it did.
             *
             * Required by the service only when the assessment CHANGES either value.
             * A silent reclassification is the thing a requestor will dispute, and
             * "we changed it" with no reason is not an answer.
             */
            $table->text('reclassification_reason')->nullable();

            $table->text('notes')->nullable();

            /*
             * When the review was completed.
             *
             * Null while the request is still being assessed. The row is created when
             * assessment STARTS, so an assessment in progress is visible — a row
             * created only on completion would make "nobody has looked at this yet"
             * and "somebody is looking at it now" indistinguishable.
             */
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            // One assessment per request: a second would mean two people assessed it
            // and nothing says which is authoritative.
            $table->unique('request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('completeness_assessments');
    }
};
