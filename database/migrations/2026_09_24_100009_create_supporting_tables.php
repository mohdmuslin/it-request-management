<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attachments, comments, audit log and notifications.
 *
 * The supporting tables. Two of them are append-only by design, and that is
 * enforced by having no update path in the application rather than by a database
 * restriction — which means the discipline has to be visible in the code review.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Document metadata. The file itself lives on a private disk.
         *
         * WHY NOT A BLOB
         *
         * The brief's §5.2 requires binary documents to be kept outside the
         * relational database. A BLOB column would make every backup enormous and
         * every query slower, and would not be streamable without loading the whole
         * file into PHP memory.
         */
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('it_requests')->cascadeOnDelete();

            $table->string('category', 50)->nullable();   // @EDITME category list unknown
            $table->string('original_name');

            /*
             * Path on the private disk, outside the document root.
             *
             * Downloads are streamed through an authorised action, never served
             * directly. That is the difference between a document a requestor can
             * share and a data leak — a predictable public URL is not access
             * control.
             */
            $table->string('storage_path', 500);

            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');

            /*
             * Integrity check, so a corrupted or substituted file is detectable.
             *
             * Cheap to compute on upload and the only way to tell later whether the
             * bytes on disk are still the bytes that were approved.
             */
            $table->char('checksum', 64)->nullable();

            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('request_id');
            $table->index('category');
        });

        /*
         * Comments, with an internal flag.
         *
         * `is_internal` implements the brief's §6.3 requirement to keep applicant
         * content separate from internal governance discussion. A requestor must
         * never see an internal note — and that is enforced in the query, not by
         * hiding a tab, because a hidden tab is not a control.
         */
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('it_requests')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            $table->boolean('is_internal')->default(false);
            $table->timestamps();

            $table->index(['request_id', 'is_internal']);
        });

        /*
         * The audit trail.
         *
         * Before/after as JSON, because the set of auditable fields spans a dozen
         * tables and a normalised column-per-field design would need a new column
         * for every future field.
         *
         * Append-only: NO update or DELETE path exists in the application, and none
         * may be added. NFR-006 requires critical records to be immutable to
         * ordinary users, and a record that can be edited is not evidence.
         */
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // Null for system actions, for the same reason as workflow_histories.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('auditable_type');
            $table->unsignedBigInteger('auditable_id');
            $table->string('event', 50);

            $table->json('old_values_json')->nullable();
            $table->json('new_values_json')->nullable();

            /*
             * The request this change relates to, denormalised deliberately.
             *
             * An audit query is almost always "everything that happened to this
             * request", and the auditable row may by then be gone or of a type that
             * cannot be joined cheaply. Storing it makes the query a single indexed
             * lookup.
             */
            $table->foreignId('request_id')->nullable()->constrained('it_requests')->nullOnDelete();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['request_id', 'created_at']);
            $table->index('event');
            $table->index('created_at');
        });

        /*
         * Notification tracking.
         *
         * WHY EVERY SEND IS RECORDED
         *
         * Notification jobs run hourly and are idempotent, which means each one has
         * to know what it already sent. Without this table an hourly reminder job
         * is an hourly spam job.
         *
         * The `error` column matters more than it looks: on this host there is no
         * shell to inspect, so a failed send that leaves no trace is
         * indistinguishable from a notification that was never due.
         */
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('request_id')->nullable()->constrained('it_requests')->cascadeOnDelete();

            $table->string('template', 80);
            $table->string('channel', 20)->default('mail');
            $table->string('status', 20)->default('pending');   // pending|sent|failed
            $table->timestamp('sent_at')->nullable();
            $table->text('error')->nullable();

            $table->timestamps();

            // Supports the idempotency check: "was this template already sent to
            // this user about this request, and did it succeed?"
            $table->index(['user_id', 'request_id', 'template', 'status'], 'notification_idempotency_index');
            $table->index('status');
        });

        /*
         * Settings, for values an administrator changes without a deployment.
         *
         * The values here override config/itrequest.php where the two overlap, so
         * the config file documents the default and the database holds the current
         * answer.
         */
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->text('value')->nullable();
            $table->string('type', 20)->default('string');   // string|int|bool|json
            $table->string('group', 50)->default('general');
            $table->timestamps();

            $table->index('group');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('comments');
        Schema::dropIfExists('attachments');
    }
};
