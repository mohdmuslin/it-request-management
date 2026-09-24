<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Departments and divisions.
 *
 * WHY THIS RUNS BEFORE THE USERS MIGRATION
 *
 * `users` carries foreign keys to both, so both must exist first. The filename
 * prefix is deliberate — Laravel orders migrations by filename, and `0000_` sorts
 * ahead of the framework's `0001_01_01_000000_create_users_table`.
 *
 * WHY `head_user_id` IS MISSING HERE
 *
 * The relationship is circular: a department has a head, who is a user, and a user
 * belongs to a department. Neither table can be created with the foreign key in
 * place. The column is added in a later migration, once `users` exists — this is
 * the standard resolution and is why the two migrations are separate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('is_active');
        });

        Schema::create('divisions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('divisions');
        Schema::dropIfExists('departments');
    }
};
