<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reference data: tiers, classifications, governance routes, review units.
 *
 * WHY THESE ARE TABLES AND NOT ENUMS ALONE
 *
 * The enums define what the values *are*; these tables are what keeps them
 * editable. An administrator can add a tier or rename a classification without a
 * deployment — which matters because the real classification list is not yet
 * confirmed, and the SharePoint list currently allows a free-typed fourth value
 * that nobody can report on.
 *
 * `code` is the stable key referenced by foreign keys and by seed data. `name` is
 * what a human reads and may be reworded freely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('is_active');
        });

        Schema::create('classifications', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('is_active');
        });

        Schema::create('governance_routes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('description')->nullable();

            /*
             * The routing rule, as data.
             *
             * Only Full sets this true, which is why only Full reaches the ITIC.
             * Storing it rather than deriving it from the code means the rule can
             * be changed by an administrator, and that the reason a request did or
             * did not reach the committee is visible in the database rather than
             * buried in a match() expression.
             */
            $table->boolean('requires_committee')->default(false);

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('is_active');
        });

        Schema::create('review_units', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('is_active');
        });

        /*
         * Which units must review a request, by classification.
         *
         * WHY THIS IS A PIVOT AND NOT A MATCH EXPRESSION
         *
         * The requirement is that the reviewing units depend on the
         * classification, and the exact mapping is not yet confirmed by the
         * business. Seeded with all three units against every classification, this
         * makes the real rule a data change rather than a code change — so the
         * answer can arrive after the build without a release.
         */
        Schema::create('classification_review_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classification_id')->constrained()->cascadeOnDelete();
            $table->foreignId('review_unit_id')->constrained()->cascadeOnDelete();

            // Sequence determines which unit's recommendation reads first on the
            // detail screen, so the display order is deliberate rather than
            // whatever the database happens to return.
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->unique(['classification_id', 'review_unit_id'], 'classification_review_unit_unique');
        });

        /*
         * Which users belong to which review unit.
         *
         * Separate from roles. A Technical Reviewer holds the role AND belongs to a
         * unit — the same role behaves differently depending on the unit, and
         * conflating the two would make "who must review this?" unanswerable.
         */
        Schema::create('review_unit_user', function (Blueprint $table) {
            $table->foreignId('review_unit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_lead')->default(false);
            $table->primary(['review_unit_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_unit_user');
        Schema::dropIfExists('classification_review_units');
        Schema::dropIfExists('review_units');
        Schema::dropIfExists('governance_routes');
        Schema::dropIfExists('classifications');
        Schema::dropIfExists('tiers');
    }
};
