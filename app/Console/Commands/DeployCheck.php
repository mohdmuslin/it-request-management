<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Department;
use App\Models\Holiday;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Pre-flight check for a live deployment.
 *
 * WHY THIS IS A SEPARATE COMMAND FROM THE DEPLOY
 *
 * The deploy makes changes; this one only reads. Running a validator that also writes
 * means you cannot check the live site without altering it, and the check is most
 * useful exactly when you are least willing to change anything.
 *
 * WHY IT EXISTS AT ALL
 *
 * Every failure this catches is SILENT in production. A missing public holiday does not
 * raise anything — it just moves a due date by a day. An administrator without a
 * department does not fail — the request just reports against nothing. Nobody notices
 * any of them until a report is wrong months later, and then the cause is very hard to
 * find.
 *
 * Exit code is non-zero when something needs attention, so it can gate a cron job or be
 * read from a log without a human squinting at the output.
 */
class DeployCheck extends Command
{
    protected $signature = 'itrequest:deploy-check
                            {--strict : Treat warnings as failures}';

    protected $description = 'Verify the configuration, data and environment are ready for live use';

    /** @var array<int, array{level: string, message: string}> */
    private array $findings = [];

    public function handle(): int
    {
        $this->line('IT Request Management — pre-flight check');
        $this->line('Environment: '.app()->environment());
        $this->line('URL:         '.config('app.url'));
        $this->line('Database:    '.config('database.default'));
        $this->newLine();

        $this->checkEnvironment();
        $this->checkStorage();
        $this->checkDatabase();
        $this->checkReferenceData();
        $this->checkOrganisation();
        $this->checkCalendar();
        $this->checkAccess();
        $this->checkEmail();

        $this->report();

        $failures = collect($this->findings)->where('level', 'fail')->count();
        $warnings = collect($this->findings)->where('level', 'warn')->count();

        if ($failures > 0) {
            return self::FAILURE;
        }

        if ($this->option('strict') && $warnings > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function flagFail(string $message): void
    {
        $this->findings[] = ['level' => 'fail', 'message' => $message];
    }

    private function flagWarn(string $message): void
    {
        $this->findings[] = ['level' => 'warn', 'message' => $message];
    }

    private function flagPass(string $message): void
    {
        $this->findings[] = ['level' => 'ok', 'message' => $message];
    }

    // ---- Environment ---------------------------------------------------------

    private function checkEnvironment(): void
    {
        /*
         * APP_KEY is a FAILURE, not a warning: without it every session is invalid and
         * anything stored encrypted is unreadable. The application appears to work until
         * somebody signs in.
         */
        (string) config('app.key') === ''
            ? $this->flagFail('APP_KEY is empty. Run `php artisan itrequest:install`.')
            : $this->flagPass('APP_KEY is set.');

        /*
         * Debug mode in production exposes stack traces, file paths and configuration
         * values to anyone who can make a page error. A FAILURE because the exposure is
         * immediate and complete.
         */
        if (app()->environment('production') && (bool) config('app.debug')) {
            $this->flagFail('APP_DEBUG is on in production. Error pages will expose file paths and configuration.');
        } elseif (app()->environment('production')) {
            $this->flagPass('Debug mode is off.');
        } else {
            $this->flagWarn('Not running in the production environment (this is '.app()->environment().').');
        }

        if (str_starts_with((string) config('app.url'), 'https://')) {
            $this->flagPass('Application URL uses HTTPS.');
        } else {
            $this->flagWarn('APP_URL is not HTTPS. Session cookies should be marked secure on a live site.');
        }
    }

    private function checkStorage(): void
    {
        foreach (['framework/views', 'framework/cache', 'logs'] as $directory) {
            if (! is_dir(storage_path($directory))) {
                $this->flagFail("storage/{$directory} is missing. The application will fail with an error that does not name this.");
            }
        }

        if (! is_writable(storage_path('framework/views')) || ! is_writable(storage_path('logs'))) {
            $this->flagFail('storage/framework/views or storage/logs is not writable by the web server.');
        } else {
            $this->flagPass('Storage directories exist and are writable.');
        }

        if (! is_dir(storage_path('app/private/attachments'))) {
            $this->flagWarn('storage/app/private/attachments is missing. Document uploads will fail.');
        }
    }

    private function checkDatabase(): void
    {
        try {
            DB::connection()->getPdo();
            $this->flagPass('Database connection works.');
        } catch (\Throwable $e) {
            $this->flagFail('Cannot connect to the database: '.$e->getMessage());

            return;
        }

        /*
         * Counted from the migrations TABLE against the files on disk.
         *
         * Deliberately not parsed from `migrate:status` output, which is decorated with
         * borders and changes format between Laravel versions — a check that silently
         * stops working is worse than no check.
         */
        try {
            $ran = DB::table('migrations')->count();
            $available = count(glob(database_path('migrations/*.php')));

            if ($ran < $available) {
                $this->flagWarn("Only {$ran} of {$available} migrations have run. Run `php artisan migrate --force`.");
            } else {
                $this->flagPass("All {$available} migrations have run.");
            }
        } catch (\Throwable) {
            $this->flagFail('The migrations table is missing — the schema has not been created.');
        }
    }

    private function checkReferenceData(): void
    {
        $counts = [
            'workflow stages' => DB::table('workflow_stages')->count(),
            'tiers' => DB::table('tiers')->where('is_active', true)->count(),
            'classifications' => DB::table('classifications')->where('is_active', true)->count(),
            'governance routes' => DB::table('governance_routes')->where('is_active', true)->count(),
            'review units' => DB::table('review_units')->where('is_active', true)->count(),
        ];

        foreach ($counts as $label => $count) {
            $count === 0
                ? $this->flagFail("No {$label} exist. The wizard will have nothing to offer.")
                : $this->flagPass(ucfirst($label).": {$count} active.");
        }

        /*
         * A classification with no assigned review units means requests of that type go
         * straight from assessment to consolidation with no technical opinion — which
         * looks like the review stage being skipped, and is.
         */
        $unmapped = DB::table('classifications')
            ->where('is_active', true)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('classification_review_units')
                ->whereColumn('classification_review_units.classification_id', 'classifications.id'))
            ->pluck('name');

        if ($unmapped->isNotEmpty()) {
            $this->flagWarn('These classifications have no reviewing units, so their requests skip technical review: '
                .$unmapped->join(', ').'. Assign units in Reference data.');
        }
    }

    /**
     * Departments and divisions must exist before anybody can raise a request.
     *
     * Both are required fields in wizard step 1, so an empty list means the wizard is
     * unusable — and it fails at the last step of a form the requestor has already
     * filled in.
     */
    private function checkOrganisation(): void
    {
        $departments = Department::where('is_active', true)->count();

        if ($departments === 0) {
            $this->flagFail('No departments exist. The request wizard cannot be completed without one.');
        } else {
            $this->flagPass("Departments: {$departments}.");
        }

        $withHead = Department::whereNotNull('head_user_id')->count();

        if ($departments > 0 && $withHead === 0) {
            $this->flagWarn('No department has a head user set. Reports that group by head will show nothing.');
        }
    }

    /**
     * The calendar.
     *
     * Running without public holidays is not an error — the application works — but
     * every due date that lands on a holiday will be wrong, and the aging report will
     * report delays nobody could have avoided.
     */
    private function checkCalendar(): void
    {
        $year = (int) now()->format('Y');
        $holidays = Holiday::whereYear('date', $year)->count();

        if ($holidays === 0) {
            $this->flagWarn("No public holidays are recorded for {$year}. Due dates will fall on days the office is closed. Add them in Due dates and calendar.");
        } else {
            $this->flagPass("Public holidays recorded for {$year}: {$holidays}.");
        }

        $stagesWithoutTargets = DB::table('workflow_stages')
            ->where('is_approval', true)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('stage_due_days')
                ->whereColumn('stage_due_days.workflow_stage_id', 'workflow_stages.id')
                ->whereNull('stage_due_days.tier_id'))
            ->pluck('name');

        if ($stagesWithoutTargets->isNotEmpty()) {
            /*
             * Not a failure, deliberately. A stage with no agreed target is a legitimate
             * state — it is the position the current process is in for every stage — and
             * the application states that rather than inventing a deadline. But it means
             * those requests can never be reported as overdue, so it is worth saying.
             */
            $this->flagWarn('These approval stages have no default target, so their requests can never be reported overdue: '
                .$stagesWithoutTargets->join(', ').'. Set targets in Due dates and calendar.');
        } else {
            $this->flagPass('Every approval stage has a default target.');
        }
    }

    private function checkAccess(): void
    {
        $active = User::where('is_active', true)->count();
        $admins = User::where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->where('name', UserRole::Administrator->value))
            ->count();

        if ($admins === 0) {
            $this->flagFail('No active administrator exists. Nobody can manage users, reference data or the calendar.');
        } elseif ($admins === 1) {
            $this->flagWarn('Only one active administrator. If that account is lost, nobody can administer the system.');
        } else {
            $this->flagPass("Active administrators: {$admins}.");
        }

        if ($active === 0) {
            $this->flagFail('No active users exist. Nobody can sign in.');
        }

        /*
         * A user with no role can sign in and reach almost nothing. Worth flagging
         * because the symptom they report is "the system is empty", which points at the
         * application rather than at their account.
         */
        $roleless = User::where('is_active', true)
            ->whereDoesntHave('roles')
            ->pluck('email');

        if ($roleless->isNotEmpty()) {
            $this->flagWarn('These accounts hold no role and can reach almost nothing: '.$roleless->take(5)->join(', ')
                .($roleless->count() > 5 ? ' (and '.($roleless->count() - 5).' more)' : '').'.');
        }

        /*
         * A Technical Reviewer in no review unit cannot file a recommendation, because
         * the units assigned to a request decide who reviews it.
         */
        $unitless = User::where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->where('name', UserRole::TechnicalReviewer->value))
            ->whereDoesntHave('reviewUnits')
            ->pluck('email');

        if ($unitless->isNotEmpty()) {
            $this->flagWarn('These technical reviewers belong to no review unit, so they cannot file recommendations: '
                .$unitless->take(5)->join(', ').'.');
        }
    }

    /**
     * Email — the Phase C gate.
     *
     * Reported as a WARNING rather than a failure, because the application is entirely
     * usable without it: notifications are recorded in the `notifications` table with
     * status `pending`, so nothing is lost and the feature can be switched on later.
     *
     * But four required features depend on delivery, so it is stated on every check
     * rather than only in a document somebody read once.
     */
    private function checkEmail(): void
    {
        $enabled = (bool) config('itrequest.notifications.mail_enabled');
        $mailer = (string) config('mail.default');

        if (! $enabled) {
            $this->flagWarn('Email delivery is OFF (ITREQUEST_MAIL_ENABLED is false). Assignment, decision, reminder and escalation emails are recorded but not sent. Set it to true once a real message has been received.');

            return;
        }

        if ($mailer === 'log' || $mailer === 'array') {
            $this->flagFail("Email is enabled but MAIL_MAILER is '{$mailer}', which writes to a file instead of sending. Nothing will reach an inbox.");
        } else {
            $this->flagPass("Email is enabled via the '{$mailer}' mailer. Send one test message to be sure.");
        }

        $queued = DB::table('notifications')->where('status', 'pending')->count();
        $failed = DB::table('notifications')->where('status', 'failed')->count();

        if ($failed > 0) {
            $this->flagWarn("{$failed} notification(s) have failed to send. Read them from the Admin audit area.");
        }

        if ($queued > 0) {
            $this->flagWarn("{$queued} notification(s) are recorded but not yet sent. Check the queue worker is running on cron.");
        }
    }

    // ---- Report --------------------------------------------------------------

    private function report(): void
    {
        $this->newLine();

        /*
         * EVERY finding goes to stdout, through `line()`.
         *
         * `$this->warn()` and `$this->error()` write to STDERR, and mixing the two
         * streams splits the report: redirecting the output to a file captures only the
         * findings that went to stdout, so the warnings and failures vanish while the
         * summary still counts them. That is exactly what happened on the first run of
         * this command — the file held "14 warning(s)" and none of the 14.
         *
         * This command exists to be read from a log by somebody with no shell, so a
         * report that loses half its content when redirected defeats its whole purpose.
         * The marks carry the severity instead, which reads the same on screen and in a
         * file.
         */
        foreach ($this->findings as $finding) {
            $mark = match ($finding['level']) {
                'ok' => '  [ok] ',
                'warn' => '  [ ! ] ',
                'fail' => '  [ X ] ',
            };

            $this->line($mark.$finding['message']);
        }

        $failures = collect($this->findings)->where('level', 'fail')->count();
        $warnings = collect($this->findings)->where('level', 'warn')->count();
        $passed = collect($this->findings)->where('level', 'ok')->count();

        $this->newLine();
        $this->line("{$passed} passed, {$warnings} warning(s), {$failures} failure(s).");
        $this->newLine();

        if ($failures > 0) {
            $this->line('Resolve the FAILURES before going live.');
        } elseif ($warnings > 0) {
            $this->line('No failures. Read the warnings — each one names something that will look');
            $this->line('like a bug later if it is left.');
        } else {
            $this->line('Nothing outstanding.');
        }
    }
}
