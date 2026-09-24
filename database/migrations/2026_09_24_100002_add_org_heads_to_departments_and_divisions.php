<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the department and division heads.
 *
 * This is the second half of the circular relationship resolved in
 * `0000_01_01_000001`. `users` now exists, so the foreign keys can be added.
 *
 * `nullOnDelete` rather than `cascadeOnDelete`: if a head leaves, the department
 * must not disappear with them. A department with no head is a normal state that
 * needs fixing; a missing department is data loss.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->foreignId('head_user_id')->nullable()->after('name')
                ->constrained('users')->nullOnDelete();
        });

        Schema::table('divisions', function (Blueprint $table) {
            $table->foreignId('head_user_id')->nullable()->after('name')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('head_user_id');
        });

        Schema::table('divisions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('head_user_id');
        });
    }
};
