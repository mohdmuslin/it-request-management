<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Routine deployment steps, run after files have been uploaded.
 *
 * WHY THIS IS SEPARATE FROM THE INSTALL
 *
 * This command is safe to run repeatedly and unattended, because the deploy runs it on
 * every release. It therefore does NOT generate a key, does NOT seed, and does NOT
 * touch anything that would be destructive if run twice. Those belong to
 * `itrequest:install`, which refuses to run against an installed application.
 *
 * WHY IT RUNS ON THE SERVER AT ALL
 *
 * The deployment workflow uploads finished files and then cannot do anything else —
 * there is no shell on the host, so the workflow's last act is to leave a flag file
 * that a cron job picks up. This command is what the cron job runs.
 *
 * ORDER MATTERS, AND EACH STEP SAYS WHY.
 */
class RunDeployment extends Command
{
    protected $signature = 'itrequest:deploy
                            {--skip-migrate : Do not run migrations (for a release with no schema change)}
                            {--dry-run : Report what would happen without changing anything}';

    protected $description = 'Run the post-upload deployment steps: storage tree, caches, migrations, health check';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $this->line('Environment: '.app()->environment());
        $this->newLine();

        /*
         * 1. Storage tree FIRST.
         *
         * Everything after this assumes it exists — the cache writer, the view
         * compiler, the log writer. A missing `storage/framework/views` produces "View
         * path not found", and a missing `storage/logs` means that error cannot be
         * logged, so the log looks empty and the application looks innocent.
         */
        $this->step('Ensuring the storage tree');

        if (! $dry) {
            $this->ensureStorageTree();
        }

        $this->line('  done');

        /*
         * 2. Clear the caches BEFORE migrating.
         *
         * `config:cache` writes a compiled config file that is loaded instead of the
         * `.env`, and a stale one makes `migrate` connect using the OLD database
         * credentials — so a release that changed the database name migrates the wrong
         * database, successfully and silently.
         */
        $this->step('Clearing stale caches');

        if (! $dry) {
            // `optimize:clear` also removes the compiled views, which is what makes an
            // edited Blade template actually appear after an upload. Without it, the
            // server keeps rendering the old template and the deploy looks like it did
            // nothing.
            Artisan::call('optimize:clear');
        }

        $this->line('  done');

        /*
         * 3. Migrate.
         *
         * `--force` because the environment is production and Laravel otherwise prompts,
         * which on a cron job means hanging until the job times out — with the migration
         * half-applied and nothing in the log to say why.
         */
        if ($this->option('skip-migrate')) {
            $this->step('Skipping migrations (--skip-migrate)');
        } else {
            $this->step('Running migrations');

            if (! $dry) {
                $code = Artisan::call('migrate', ['--force' => true]);
                $this->line(Artisan::output());

                if ($code !== 0) {
                    $this->error('Migrations failed. The previous release\'s code is still on disk,');
                    $this->error('so the site may be inconsistent. Fix forward as soon as possible.');

                    return self::FAILURE;
                }
            } else {
                Artisan::call('migrate', ['--pretend' => true, '--force' => true]);
                $this->line(Artisan::output());
            }
        }

        /*
         * 4. Rebuild the caches.
         *
         * AFTER migrating, not before: `config:cache` and `route:cache` read the
         * application, and caching them before a migration that adds a config key would
         * bake in the old shape.
         *
         * `route:cache` is deliberately NOT included. Route caching has a long history of
         * failing on routes registered from closures or component classes, and this
         * application registers every route from a Livewire component. The saving on a
         * handful of routes is not worth a release that 500s on every page.
         *
         * NOT RUN OUTSIDE PRODUCTION, and that is a real guard rather than a convenience.
         *
         * `config:cache` replaces the configuration repository with a compiled file and
         * forces the container to rebuild. In the same process that means the command
         * continues with a different config object than it started with — and against an
         * in-memory SQLite database, the rebuild opens a NEW connection, which is a NEW
         * EMPTY database. This was reproduced: the verification step in this very command
         * then reported "no such table: workflow_stages", a failure the command had
         * caused itself.
         *
         * On a real server the database is MySQL and persists across connections, so the
         * cache rebuild is safe and wanted. Locally it is actively unhelpful: a cached
         * config file is loaded INSTEAD of `.env`, so the next edit to `.env` appears to
         * do nothing and the cause is very hard to find.
         */
        if (app()->environment('production')) {
            $this->step('Rebuilding caches');

            if (! $dry) {
                Artisan::call('config:cache');
                Artisan::call('view:cache');
            }

            $this->line('  done');
        } else {
            $this->step('Skipping caches (not the production environment)');
        }

        /*
         * 5. Verify, rather than assume.
         *
         * A deploy that reports success while the site is broken is worse than one that
         * fails: nobody looks. These checks are cheap and they catch the failures that
         * actually happen — a missing front controller, a database that cannot be read.
         *
         * SKIPPED IN A DRY RUN, deliberately.
         *
         * A dry run applies nothing: it skips the migration, so the schema may not match
         * the code on disk. Verifying then reports a problem the dry run itself caused,
         * which is worse than not verifying at all — the operator sees a failure, cannot
         * tell whether it is real, and learns to distrust the check.
         */
        if ($dry) {
            $this->step('Skipping verification (dry run — nothing was applied)');
            $this->newLine();
            $this->info('Dry run complete. Nothing was changed.');

            return self::SUCCESS;
        }

        $this->step('Verifying');

        $problems = $this->problems();

        if ($problems) {
            foreach ($problems as $problem) {
                $this->error('  '.$problem);
            }

            return self::FAILURE;
        }

        $this->line('  all checks passed');

        $this->newLine();
        $this->info('Deployment complete.');

        return self::SUCCESS;
    }

    /**
     * What is wrong, if anything.
     *
     * Returns a LIST rather than failing on the first problem, so a single run reports
     * everything rather than revealing one fault per deploy attempt.
     *
     * @return array<int, string>
     */
    private function problems(): array
    {
        $problems = [];

        foreach (['framework/views', 'framework/cache', 'logs'] as $directory) {
            if (! is_dir(storage_path($directory))) {
                $problems[] = "storage/{$directory} is missing — the application will fail with a message that does not name this.";
            }
        }

        try {
            DB::connection()->getPdo();
            DB::table('workflow_stages')->count();
        } catch (\Throwable $e) {
            $problems[] = 'Cannot read the database: '.$e->getMessage();
        }

        if (! is_file(public_path('index.php'))) {
            $problems[] = 'public/index.php is missing — the web server has nothing to execute and every request returns 403.';
        }

        if (! is_file(public_path('build/manifest.json'))) {
            $problems[] = 'public/build/manifest.json is missing — the frontend was not built, and every page renders blank.';
        }

        if ((string) config('app.key') === '') {
            $problems[] = 'APP_KEY is empty — sessions and encrypted values will not work. Run itrequest:install.';
        }

        /*
         * A production environment running with debug on exposes stack traces, file
         * paths and configuration values to anyone who can trigger an error. Worth
         * failing the deploy over, because it is otherwise invisible until somebody
         * deliberately breaks a page.
         */
        if (app()->environment('production') && (bool) config('app.debug')) {
            $problems[] = 'APP_DEBUG is on in production — error pages will expose file paths and configuration.';
        }

        return $problems;
    }

    private function step(string $label): void
    {
        $this->line($label.'…');
    }

    /** See InstallApplication::ensureStorageTree() for why these directories matter. */
    private function ensureStorageTree(): void
    {
        foreach ([
            'app/private/attachments',
            'framework/cache/data',
            'framework/sessions',
            'framework/views',
            'logs',
        ] as $directory) {
            $path = storage_path($directory);

            if (! is_dir($path)) {
                mkdir($path, 0755, true);
            }
        }
    }
}
