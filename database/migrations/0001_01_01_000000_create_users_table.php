<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('employee_no', 50)->nullable();

            /*
             * Entra object id.
             *
             * WHY THIS COLUMN IS HERE FROM THE FIRST MIGRATION
             *
             * The POC authenticates locally, but production uses Microsoft Entra ID.
             * Adding this column later means matching existing rows to Entra
             * identities by email — which fails for anyone whose email has changed,
             * and cannot be verified automatically. One nullable column now costs
             * nothing and removes a data-migration project later.
             */
            $table->char('entra_object_id', 36)->nullable()->unique();

            $table->string('name');
            $table->string('email')->unique();

            /*
             * Department and division.
             *
             * Nullable, because they are filled from the identity provider when a
             * request is created rather than typed here. They exist on the user as
             * the default for the next request, and on the request as a snapshot —
             * see it_requests, where the same values are copied at submission.
             */
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('division_id')->nullable()->constrained('divisions')->nullOnDelete();

            /*
             * Reporting line.
             *
             * Used for reporting and as an escalation target. Deliberately NOT the
             * approval mechanism — approvals use the Owner and Sponsor named on the
             * request, because a named approver is accountable and a role queue lets
             * a request sit unowned.
             */
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('email_verified_at')->nullable();

            /*
             * Nullable, because when the identity provider authenticates there is no
             * local password. A non-nullable column would force a fake value.
             */
            $table->string('password')->nullable();

            $table->rememberToken();

            /*
             * Deactivation replaces deletion.
             *
             * History references users — an approver who left still needs to appear
             * on the requests they decided. A row that can disappear cannot be
             * audited.
             */
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index('is_active');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
