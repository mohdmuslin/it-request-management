<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Enums\WorkflowStage;
use App\Models\ApprovalTask;
use App\Models\Delegation;
use App\Models\ItRequest;
use App\Models\Notification as NotificationRecord;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Notifications, recorded before they are sent.
 *
 * WHY EVERY SEND IS RECORDED FIRST
 *
 * The scheduled jobs run hourly and are idempotent, which means each one has to know
 * what it already sent. A row written BEFORE the send is the only way to guarantee
 * that: if the process dies mid-send, the record exists and the next run does not
 * retry. The alternative — record after sending — turns a crash into an hourly spam
 * loop, because the row was never written and the job sends again.
 *
 * WHY FAILURES ARE RECORDED RATHER THAN THROWN
 *
 * This host has no shell and no worker. A notification that throws would abort
 * whatever caused it — so a decision would fail because an email could not be sent,
 * which is the wrong way round: the decision is the record, the email is a courtesy.
 * The failure is written to the row and to the log, and `notifications.failed_count`
 * is answerable from the admin screen.
 *
 * THE EMAIL GATE
 *
 * Phase C's gate — "one real email received from this host" — has not been proven.
 * Until it is, `mail_enabled` defaults to false and these methods record what WOULD
 * have been sent without attempting delivery. That makes the notification trail
 * visible and testable now, and turns the gate into a config change rather than a
 * rewrite. It is a declared deviation, not an oversight.
 */
class NotificationService
{
    /**
     * Tell the approver a request is waiting on them.
     *
     * Sent when a task is created. The recipient is the person who can actually act
     * — the delegate when a delegation is in force, not the absent approver, because
     * telling somebody on leave that they have work is how a request sits untouched.
     */
    public function notifyAssignment(ApprovalTask $task): ?NotificationRecord
    {
        $task->loadMissing('request', 'approver');

        if (! $task->request) {
            return null;
        }

        /*
         * Resolve the delegate at send time.
         *
         * Deliberately not read from the task's approver: a delegation may begin
         * after the task was created, and the person who should be told is whoever
         * can act NOW.
         */
        $recipient = $this->actingApprover($task);

        if (! $recipient) {
            // An unassigned task has nobody to notify. Recorded as a log line rather
            // than a notification row, because there is no recipient to attach one
            // to — and an unassigned task is a governance problem, not a mail one.
            Log::warning('Approval task has no approver to notify.', [
                'task_id' => $task->id,
                'request' => $task->request->request_no,
                'stage' => $task->stage,
            ]);

            return null;
        }

        $request = $task->request;

        $body = "A request is waiting for your decision.\n\n"
            ."Request: {$request->request_no}\n"
            ."Title: {$request->title}\n"
            .'Stage: '.WorkflowStage::from($task->stage)->label()."\n"
            .($task->due_at ? 'Target: '.$task->due_at->format('d M Y')."\n" : '')
            ."\nOpen the request: ".route('requests.show', $request);

        return $this->send(
            user: $recipient,
            template: 'approval.assigned',
            subject: "Decision needed: {$request->request_no}",
            body: $body,
            request: $request,
        );
    }

    /**
     * Tell the requestor the outcome of a decision.
     *
     * The returned request is the case the requestor most needs to know about and
     * least often hears about — the current process gives them no notification at
     * all, so they learn the status by asking.
     */
    public function notifyDecision(ApprovalTask $task): ?NotificationRecord
    {
        $task->loadMissing('request.requestor', 'approver');

        $request = $task->request;

        if (! $request?->requestor) {
            return null;
        }

        $decision = $task->decision?->label() ?? 'Decided';

        $body = "Your request has been {$decision}.\n\n"
            ."Request: {$request->request_no}\n"
            ."Title: {$request->title}\n"
            .'Decided by: '.($task->approver?->name ?? 'the approver')."\n"
            .($task->comments ? "\nComment:\n{$task->comments}\n" : '')
            ."\nView the request: ".route('requests.show', $request);

        return $this->send(
            user: $request->requestor,
            template: 'decision.recorded',
            subject: "{$request->request_no}: {$decision}",
            body: $body,
            request: $request,
        );
    }

    /**
     * Remind an approver that a decision is due soon.
     *
     * Idempotent through `updated_at` on the task, not through this method: the
     * caller marks `reminded_at` so a re-run of the command does not send twice. The
     * guard is checked here too, because a caller that forgot would mean an hourly
     * reminder job sending hourly.
     */
    public function notifyReminder(ApprovalTask $task): ?NotificationRecord
    {
        if ($task->reminded_at !== null) {
            return null;
        }

        $recipient = $this->actingApprover($task);

        if (! $recipient) {
            return null;
        }

        $task->loadMissing('request');
        $request = $task->request;

        $body = "A request awaiting your decision is approaching its target date.\n\n"
            ."Request: {$request->request_no}\n"
            ."Title: {$request->title}\n"
            .($task->due_at ? 'Target: '.$task->due_at->format('d M Y')."\n" : '')
            ."\nOpen the request: ".route('requests.show', $request);

        $sent = $this->send(
            user: $recipient,
            template: 'approval.reminder',
            subject: "Reminder: {$request->request_no} is due soon",
            body: $body,
            request: $request,
        );

        // Marked whether or not delivery succeeded. A failed send that is retried
        // every hour is worse than one that failed once and is visible in the
        // notification log — the failure has a recorded row either way.
        $task->forceFill(['reminded_at' => now()])->save();

        return $sent;
    }

    /** Escalate a task that is past its due date. */
    public function notifyEscalation(ApprovalTask $task): ?NotificationRecord
    {
        if ($task->escalated_at !== null) {
            return null;
        }

        $recipient = $this->actingApprover($task);

        if (! $recipient) {
            return null;
        }

        $task->loadMissing('request');
        $request = $task->request;

        $body = "A request in your queue is past its target date.\n\n"
            ."Request: {$request->request_no}\n"
            ."Title: {$request->title}\n"
            .'Stage: '.WorkflowStage::from($task->stage)->label()."\n"
            .($task->due_at ? 'Target: '.$task->due_at->format('d M Y')."\n" : '')
            ."\nOpen the request: ".route('requests.show', $request);

        $sent = $this->send(
            user: $recipient,
            template: 'approval.overdue',
            subject: "Overdue: {$request->request_no}",
            body: $body,
            request: $request,
        );

        $task->forceFill(['escalated_at' => now(), 'breached_at' => $task->breached_at ?? now()])->save();

        return $sent;
    }

    /**
     * Tell the people responsible for a stage that a request has reached it.
     *
     * Used for the technical and consolidation stages, which have no single named
     * approver — the recipients are resolved from the role, and for the technical
     * stage from the units that must review it.
     */
    public function notifyStageReached(ItRequest $request, WorkflowStage $stage): void
    {
        $recipients = $this->recipientsForStage($request, $stage);

        if ($recipients->isEmpty()) {
            /*
             * Recorded as a log line, not a notification row.
             *
             * An unassigned stage is a real governance problem and somebody should be
             * able to see it — but there is no user to attach a notification to, so a
             * row would have to name one arbitrarily.
             */
            Log::warning('No recipient for a stage that has been reached.', [
                'request' => $request->request_no,
                'stage' => $stage->value,
            ]);

            return;
        }

        foreach ($recipients as $recipient) {
            $this->send(
                user: $recipient,
                template: 'stage.reached.'.$stage->value,
                subject: "{$request->request_no} is ready for {$stage->label()}",
                body: "A request has reached {$stage->label()}.\n\n"
                    ."Request: {$request->request_no}\n"
                    ."Title: {$request->title}\n"
                    ."\nOpen the request: ".route('requests.show', $request),
                request: $request,
            );
        }
    }

    /**
     * Who is responsible for a stage.
     *
     * The technical stage goes to the members of every assigned reviewing unit — NOT
     * to the technical review role as a whole, because a reviewer who is not in an
     * assigned unit cannot file a recommendation and would be told about work they
     * cannot do.
     */
    private function recipientsForStage(ItRequest $request, WorkflowStage $stage): Collection
    {
        if ($stage === WorkflowStage::TechnicalRecommendation) {
            $unitIds = app(GovernanceService::class)
                ->unitsFor((int) $request->classification_id)
                ->pluck('id');

            if ($unitIds->isEmpty()) {
                return collect();
            }

            return User::query()
                ->active()
                ->whereHas('reviewUnits', fn ($q) => $q->whereIn('review_units.id', $unitIds))
                ->get();
        }

        $role = match ($stage) {
            WorkflowStage::CompletenessReview => UserRole::GovernanceReviewer,
            WorkflowStage::Consolidation => UserRole::Hou,
            WorkflowStage::CommitteeDecision => UserRole::CommitteeSecretariat,
            default => null,
        };

        if ($role === null) {
            return collect();
        }

        return User::query()
            ->active()
            ->whereHas('roles', fn ($q) => $q->where('name', $role->value))
            ->get();
    }

    /**
     * Who can act on this task right now.
     *
     * The delegate when a delegation is in force, otherwise the named approver.
     * Unassigned tasks return null so the caller records the gap rather than
     * inventing a recipient.
     */
    private function actingApprover(ApprovalTask $task): ?User
    {
        if ($task->approver_id === null) {
            return null;
        }

        $delegation = Delegation::for((int) $task->approver_id);

        if ($delegation) {
            return $delegation->delegate;
        }

        return $task->approver ?? User::find($task->approver_id);
    }

    /**
     * Record and, when enabled, send.
     *
     * The row is created BEFORE the attempt. See the class comment: recording after
     * sending turns a crash mid-send into a job that sends again on every run.
     */
    private function send(
        User $user,
        string $template,
        string $subject,
        string $body,
        ?ItRequest $request = null,
    ): NotificationRecord {
        // Idempotency at the service level as well as the caller. A duplicate row
        // here would mean a duplicate email, and the check is one indexed lookup.
        if (NotificationRecord::alreadySent($user->id, $request?->id, $template)) {
            return NotificationRecord::query()
                ->where('user_id', $user->id)
                ->where('request_id', $request?->id)
                ->where('template', $template)
                ->where('status', 'sent')
                ->latest('id')
                ->firstOrFail();
        }

        $record = NotificationRecord::create([
            'user_id' => $user->id,
            'request_id' => $request?->id,
            'template' => $template,
            'channel' => 'mail',
            'status' => 'pending',
        ]);

        if (! config('itrequest.notifications.mail_enabled')) {
            /*
             * The Phase C gate is unproven, so nothing is delivered. The row stays
             * `pending` rather than being marked `sent` — marking it sent would make
             * the notification log assert a delivery that never happened, and the
             * first real send would then be invisible in the log.
             */
            Log::info('Notification recorded but not sent: mail is disabled pending the email gate.', [
                'to' => $user->email,
                'template' => $template,
                'subject' => $subject,
            ]);

            return $record;
        }

        try {
            Mail::raw($body, fn ($message) => $message->to($user->email)->subject($subject));

            $record->forceFill(['status' => 'sent', 'sent_at' => now()])->save();
        } catch (\Throwable $e) {
            // Recorded, not thrown. A decision must not fail because an email could
            // not be sent — the decision is the record, the email is a courtesy.
            $record->forceFill(['status' => 'failed', 'error' => $e->getMessage()])->save();

            Log::error('Notification failed to send.', [
                'to' => $user->email,
                'template' => $template,
                'error' => $e->getMessage(),
            ]);
        }

        return $record;
    }
}
