<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roles and permissions.
 *
 * A hand-rolled role system rather than spatie/laravel-permission, because the
 * requirements are narrow: nine fixed roles, no runtime role creation, and
 * permissions that are checked by policies rather than by name at call sites.
 *
 * The roles are seeded from the UserRole enum, so the enum remains the single
 * source of truth for what a role *is*; this table is what a user *holds*.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();   // matches a UserRole value
            $table->string('label');
            $table->string('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();   // e.g. "request.approve"
            $table->string('label');
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['permission_id', 'role_id']);
        });

        Schema::create('role_user', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * Who granted the role, and when.
             *
             * NFR-006 requires critical records to be immutable to ordinary users,
             * and role assignment is one of the few ways privilege is escalated in
             * this system. Recording the grantor means "who made them an
             * administrator?" has an answer.
             */
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('granted_at')->nullable();

            $table->primary(['role_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
