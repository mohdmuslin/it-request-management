<?php

namespace App\Console\Commands;

use App\Models\ApprovalTask;
use App\Services\BusinessCalendar;
use App\Services\NotificationService;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;

/**
 * Send reminders for approvals approaching their target, and escalate overdue ones.
 *
 * WHY THIS IS ONE COMMAND AND NOT TWO
 *
 * A task can become due-soon and overdue within the same run — a weekend elapses and
 * a target that was a day away is now two days past. Two commands would need an
 * ordering guarantee between them, and the scheduler does not offer one. One command
 * evaluating every open task decides an ordering that holds.
 *
 * WHY IT IS IDEMPOTENT RATHER THAN SCHEDULED ONCE
 *
 * `approval_tasks` carries `reminded_at` and `escalated_at`, and this command marks
 * them. A re-run is therefore harmless, which matters because adding a holiday
 * changes what a correct due date would have been — so due dates are recomputed, and
 * a recompute can move one EARLIER, making an alert that has already fired become
 * due again. The flags are what stop it firing twice.
 *
 * WHY --dry-run EXISTS
 *
 * On this host there is no shell and no worker, so the only way to see what the
 * scheduled job would do is to run it and read the output. A dry run makes that
 * possible without sending anything.
 */
class SendApprovalNotifications extends Command
{
    protected $signature = 'itrequest:notify-approvals
                            {--dry-run : Report what would be sent without sending anything}
                            {--days-before= : Business days before the target to remind, overriding config}';

    protected $description = 'Send reminders for approvals due soon and escalations for overdue ones';

    public function handle(NotificationService $notifications): int
    {
        $dry = (bool) $this->option('dry-run');

        $daysBefore = $this->option('days-before') !== null
            ? (int) $this->option('days-before')
            : (int) config('itrequest.queue.remind_days_before', 1);

        /*
         * Only tasks with a due date can be reminded or escalated.
         *
         * A task with no target is not "due soon" — it has no deadline at all, which
         * is a legitimate state (a stage with no agreed target) and one the aging
         * report states rather than invents. Chasing somebody about a date that was
         * never set trains them to ignore the reminder.
         */
        $open = ApprovalTask::query()
            ->pending()
            ->whereNotNull('due_at')
            ->with(['request.requestor', 'approver'])
            ->get();

        $reminded = 0;
        $escalated = 0;
        $skipped = 0;

        foreach ($open as $task) {
            $due = $task->due_at;

            // Already handled in a previous run. Idempotency, checked first so the
            // dry run reports the same decisions the real run would make.
            if ($task->escalated_at !== null && $task->reminded_at !== null) {
                $skipped++;

                continue;
            }

            if ($due->isPast()) {
                if ($task->escalated_at === null) {
                    $dry
                        ? $this->warnTask('ESCALATE', $task, $due)
                        : $notifications->notifyEscalation($task);

                    $escalated++;
                }

                // Overdue tasks are escalated, not also reminded — a reminder for
                // something already past its date reads as the system not noticing.
                continue;
            }

            /*
             * "Due within N BUSINESS days" uses the business calendar, not a date
             * subtraction.
             *
             * `now()->addDay()` lands on a Sunday for a Friday target and a reminder
             * goes out on a non-working day — which on this deployment means a
             * Monday-morning pile of them, exactly when the approver is busiest and
             * least likely to read them.
             */
            $threshold = app(BusinessCalendar::class)
                ->addBusinessDays(now(), $daysBefore);

            if ($due->lessThanOrEqualTo($threshold) && $task->reminded_at === null) {
                $dry
                    ? $this->warnTask('REMIND', $task, $due)
                    : $notifications->notifyReminder($task);

                $reminded++;
            }
        }

        $this->info(sprintf(
            '%s: %d open task(s) with a target — %d reminder(s), %d escalation(s), %d already handled.',
            $dry ? 'Dry run' : 'Done',
            $open->count(),
            $reminded,
            $escalated,
            $skipped,
        ));

        if (! config('itrequest.notifications.mail_enabled')) {
            $this->warn(
                'Mail is disabled (ITREQUEST_MAIL_ENABLED is false), so nothing was delivered. '
                .'Rows are recorded in the notifications table with status "pending".'
            );
        }

        return self::SUCCESS;
    }

    private function warnTask(string $action, ApprovalTask $task, CarbonInterface $due): void
    {
        $this->line(sprintf(
            '  %-9s %s  %-30s  %s  due %s',
            $action,
            $task->request?->request_no ?? '(no request)',
            $task->approver?->name ?? '(unassigned)',
            $task->stage,
            $due->format('d M Y'),
        ));
    }
}
