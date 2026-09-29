<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * First-time installation on a host with no shell access.
 *
 * WHY THIS IS SEPARATE FROM A DEPLOY
 *
 * A deploy runs repeatedly and must be safe to run unattended. An install runs ONCE
 * and does things that are destructive if repeated:
 *
 *  - `migrate` against an empty database;
 *  - `key:generate`, which sets APP_KEY.
 *
 * `key:generate` alone makes folding these together unacceptable. APP_KEY encrypts
 * anything stored encrypted and signs every session; regenerating it invalidates every
 * existing session and makes encrypted data permanently unreadable - silently, because
 * the application simply returns something that can never be decrypted.
 *
 * So install is deliberately one-shot and REFUSES to run against an application that
 * already has data.
 *
 * WHY IT CREATES AN ADMINISTRATOR
 *
 * `DemoUserSeeder` refuses outside the local environment, on purpose - it creates
 * accounts with a known password, and publishing credentials is how the sibling
 * project ended up with live accounts anybody could sign into. But that leaves a fresh
 * production install with **no user at all**: every screen sits behind `auth`, so
 * nobody can sign in and the application is unusable with no way out except SQL.
 *
 * The gap is closed here, with a RANDOM password rather than a known one. A default
 * password that ships in a repository is not a default, it is a published credential.
 *
 * HOW IT IS TRIGGERED WITHOUT A SHELL
 *
 * cPanel -> Cron Jobs, a one-off job running this command, then delete the job. It is
 * NOT reachable over HTTP: an internet-facing installer is the classic way a fresh
 * Laravel application is taken over, and this one creates an administrator.
 */
class InstallApplication extends Command
{
    protected $signature = 'itrequest:install
                            {--admin-email= : Email for the first administrator account}
                            {--admin-name= : Display name for the first administrator}
                            {--force : Proceed even if the application looks already installed}';

    protected $description = 'First-time install: create the storage tree, generate the key, migrate, seed reference data, and create the first administrator';

    public function handle(): int
    {
        $this->line('Environment: '.app()->environment());
        $this->line('Database:    '.(string) config('database.default'));
        $this->newLine();

        /*
         * FIRST, before anything else that touches the filesystem.
         *
         * A fresh server reached over FTP has no `storage/framework/views` - the deploy
         * deliberately does not upload anything under `storage/`, because that is where
         * sessions, compiled views and uploaded documents live. Without this directory
         * the framework throws "Please provide a valid cache path" while it is starting,
         * because the compiled-view path is resolved with `realpath()`, which returns
         * FALSE for a directory that is not there. That message names views rather than
         * the missing folder, and the log is empty because `storage/logs` is missing too.
         *
         * In practice `bootstrap/ensure-storage.php` has already repaired the tree by the
         * time this runs - it is required by `artisan` before anything loads, and it has
         * to be, because this command is an artisan command and could not otherwise boot
         * on a server whose tree is missing. This call remains for the case where the
         * tree is deleted while the command is running.
         */
        $this->ensureStorageTree();

        if ($this->looksInstalled() && ! $this->option('force')) {
            $this->error('This application looks already installed - refusing to run.');
            $this->newLine();
            $this->line('Re-running an install would regenerate APP_KEY, invalidating every');
            $this->line('session and making any encrypted value permanently unreadable.');
            $this->newLine();
            $this->line('Use `php artisan itrequest:deploy` for routine updates.');
            $this->line('If you are certain, pass --force.');

            return self::FAILURE;
        }

        // ---- APP_KEY --------------------------------------------------------

        if ((string) config('app.key') === '') {
            Artisan::call('key:generate', ['--force' => true]);
            $this->info('APP_KEY generated.');
        } else {
            $this->line('APP_KEY already set - left alone.');
        }

        // ---- Schema ---------------------------------------------------------

        if (Artisan::call('migrate', ['--force' => true]) !== 0) {
            $this->error('Migrations failed. Nothing further was attempted.');

            return self::FAILURE;
        }

        $this->info('Schema created.');

        // ---- Reference data --------------------------------------------------

        if (Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\ReferenceDataSeeder', '--force' => true]) !== 0) {
            $this->error('Reference data seeding failed. The schema is intact; fix and re-run with --force.');

            return self::FAILURE;
        }

        $this->info('Reference data seeded (roles, tiers, classifications, routes, units, stages).');

        // ---- The first administrator ----------------------------------------

        $password = $this->createAdministrator();

        // ---- Report ----------------------------------------------------------

        $this->newLine();
        $this->line('---');
        $this->line('Users:             '.DB::table('users')->count());
        $this->line('Workflow stages:   '.DB::table('workflow_stages')->count());
        $this->line('Classifications:   '.DB::table('classifications')->count());

        if ($password !== null) {
            $this->newLine();
            $this->warn('┌─────────────────────────────────────────────────────────────────────┐');
            $this->warn('│  THIS PASSWORD IS SHOWN ONCE. It is also written to the log file.   │');
            $this->warn('└─────────────────────────────────────────────────────────────────────┘');
            $this->newLine();
            $this->line('  Email:    '.$this->option('admin-email'));
            $this->line('  Password: '.$password);
            $this->newLine();
            $this->line('It is written to storage/logs/laravel.log as well, because cron output');
            $this->line('usually goes to /dev/null. Read it there if this scrolled past.');
        }

        $this->newLine();
        $this->info('Installation complete.');
        $this->newLine();
        $this->line('Next:');
        $this->line('  1. DELETE THIS CRON JOB from cPanel.');
        $this->line('  2. Sign in and change the administrator password from Users and roles.');
        $this->line('  3. Add the real departments and divisions (Reference data).');
        $this->line('  4. Add this year\'s public holidays (Due dates and calendar), or every');
        $this->line('     due date will fall on days the office is closed.');
        $this->line('  5. Set the stage targets, if the defaults are wrong.');

        return self::SUCCESS;
    }

    /**
     * Create the first administrator, returning the generated password.
     *
     * Returns null when an administrator already exists, so a `--force` re-run does not
     * silently replace a working account's password.
     */
    private function createAdministrator(): ?string
    {
        $existing = User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', UserRole::Administrator->value))
            ->exists();

        if ($existing) {
            $this->line('An administrator already exists - no account created.');

            return null;
        }

        $email = $this->option('admin-email') ?: $this->ask(
            'Email for the first administrator account',
            'admin@'.parse_url((string) config('app.url'), PHP_URL_HOST),
        );

        $name = $this->option('admin-name') ?: 'Administrator';

        /*
         * A RANDOM password, not a default one.
         *
         * A password that ships in a repository is a published credential - the sibling
         * project has two live accounts whose password is in the public repo. Generating
         * one means there is nothing to guess and nothing to forget to change before the
         * site is reachable.
         *
         * 24 characters from `Str::password()` is comfortably beyond brute force, and it
         * is printed once and written to the log rather than stored anywhere readable
         * afterwards - the hash is all the database keeps.
         */
        $password = Str::password(24);

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'is_active' => true,
        ]);

        $role = Role::firstOrCreate(
            ['name' => UserRole::Administrator->value],
            ['label' => UserRole::Administrator->label()],
        );

        $user->roles()->syncWithoutDetaching([$role->id => ['granted_at' => now()]]);

        /*
         * Written to the log as well as printed.
         *
         * cPanel cron output goes to /dev/null unless it is redirected, so the printed
         * password is frequently lost. The log file is readable through cPanel's File
         * Manager, which is the only interface this host offers.
         *
         * This is a deliberate, temporary exposure: the entry should be removed once the
         * password has been changed. `itrequest:set-password` exists so the account can
         * be rotated without it ever being needed again.
         */
        $this->logCredentials($email, $password);

        $this->info("Administrator created: {$email}");

        return $password;
    }

    private function logCredentials(string $email, string $password): void
    {
        Log::warning(
            'FIRST ADMINISTRATOR CREATED - change this password, then remove this log entry.',
            ['email' => $email, 'password' => $password],
        );
    }

    /**
     * Whether the schema already exists and holds data.
     *
     * The presence of the `users` table is the signal: it is created by the framework
     * migrations, so it means `migrate` has completed at least once on this database.
     */
    private function looksInstalled(): bool
    {
        try {
            return DB::getSchemaBuilder()->hasTable('users') && User::query()->exists();
        } catch (\Throwable) {
            // Cannot connect, or no tables yet. Treat as not installed and let `migrate`
            // produce the real error, which names the connection problem.
            return false;
        }
    }

    /**
     * Create the directories the application needs and the deploy cannot deliver.
     *
     * WHY THIS RUNS BEFORE ANYTHING ELSE, AND WHY IT INCLUDES `bootstrap/cache`
     *
     * Two whole trees are missing on a fresh FTP-deployed server, and each produces a failure that
     * does not name its cause.
     *
     * `storage/` is excluded from the transfer deliberately - it holds sessions, compiled views and
     * uploaded documents, and overwriting it would destroy server state. Missing
     * `storage/framework/views` makes `Compiler` throw "**Please provide a valid cache path.**",
     * because the compiled-view path is resolved with `realpath()`, which returns FALSE for a
     * directory that is not there. That message names VIEWS rather than a missing folder, and
     * missing `storage/logs` means the error cannot be logged, so the log looks empty and innocent.
     *
     * WHAT THIS METHOD CANNOT DO, AND WHY `bootstrap/ensure-storage.php` EXISTS
     *
     * This method repairs the tree, but it is an ARTISAN command, and artisan cannot boot without
     * `storage/framework/views` - it throws the message above while the framework is starting.
     * So listing paths here, however carefully ordered, never helped: the command could not run
     * on the very server it was written for. `bootstrap/ensure-storage.php` is required by BOTH
     * `artisan` and `public/index.php` and runs before anything else is loaded, which is the only
     * place a repair can happen when the thing being repaired is needed to run the repair. This
     * method stays as the second line of defence, for a tree deleted while the app is running.
     *
     * `bootstrap/cache` is missing for a subtler reason, and it is the WORSE of the two.
     * Laravel gitignores everything in it, so the only tracked file is a `.gitignore` - and the
     * deploy excludes ``the .git wildcard``. So the DIRECTORY ITSELF never arrives, and nothing in the
     * application creates it.
     *
     * What happens then: `artisan` bootstraps through `bootstrap/app.php`, which needs
     * `bootstrap/cache` to exist for its package manifest. Absent, PHP dies with a FATAL - before
     * Laravel's error handler is registered. The browser gets **500 with a ZERO-LENGTH body**, the
     * log gets nothing, and there is no message anywhere to search for.
     *
     * That exact failure happened twice in this project: first in CI, where `composer install`'s
     * `package:discover` hook failed with "The .../bootstrap/cache directory must be present and
     * writable", and then on this host, where it presented as an empty 500 and cost an afternoon.
     * The CI fix was to track `bootstrap/cache/.gitignore` - which does NOT help here, because the
     * deploy excludes dotfiles. **The directory is created by code instead, which is the only
     * mechanism that works on a host with no shell.**
     *
     * `0755` rather than `0777`: the web server runs as a different user on some cPanel setups, and
     * world-writable directories on a shared host are a real risk rather than a theoretical one.
     */
    private function ensureStorageTree(): void
    {
        $directories = [
            /*
             * FIRST, and it would have prevented the empty 500 on its own. Listed before the
             * storage paths deliberately: without it the command cannot run at all, so any
             * ordering that puts it later would never be reached.
             */
            'bootstrap/cache',

            'storage/app/private/attachments',
            'storage/framework/cache/data',
            'storage/framework/sessions',
            'storage/framework/views',
            'storage/logs',
        ];

        $created = [];

        foreach ($directories as $directory) {
            /*
             * ABSOLUTE from the application root, not `storage_path()` for every entry.
             *
             * `bootstrap/cache` is not under `storage/`, and calling `storage_path()` on it produced
             * `storage/bootstrap/cache` - a directory nothing looks in, created successfully, and
             * reported as "Storage tree ensured". The command would have appeared to fix the
             * problem while leaving `artisan` just as unable to boot.
             */
            $path = base_path($directory);

            if (! is_dir($path)) {
                mkdir($path, 0755, true);

                $created[] = $directory;
            }
        }

        $this->line($created === []
            ? 'Directory tree already present.'
            : 'Created: '.implode(', ', $created));
    }
}
