<?php

use App\Console\Commands\SendApprovalNotifications;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| WHY HOURLY RATHER THAN DAILY
|
| The job is idempotent — each task records `reminded_at` and `escalated_at` and is
| skipped once handled — so running it more often than necessary costs a query and
| nothing else. Running it daily means a target that falls due at 09:00 is reminded
| at most a day late, and an escalation can be a full day behind the breach. The
| thing being measured is how long a request waits on somebody, so being a day
| behind defeats the purpose.
|
| WHY THE QUEUE IS WORKED HERE TOO
|
| This host has no worker process and no SSH, so `queue:work --stop-when-empty` runs
| from cron. Without it, queued notifications sit in the `jobs` table forever and the
| notification log shows rows that never resolve.
|
| `--stop-when-empty` rather than a daemon: a daemon that dies is not restarted by
| anything on this host, and nothing would report that it had stopped.
|
*/

Schedule::command(SendApprovalNotifications::class)
    ->hourly()
    ->withoutOverlapping()
    ->description('Remind approvers of due tasks and escalate overdue ones');

Schedule::command('queue:work --stop-when-empty --max-time=55')
    ->everyMinute()
    // Not `withoutOverlapping`: a queue worker that is still running when the next
    // minute arrives is normal on a busy morning, and skipping a minute is better
    // than skipping the queue entirely.
    ->description('Process queued notifications');
