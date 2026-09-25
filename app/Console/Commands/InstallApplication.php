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
 * existing session and makes encrypted data permanently unreadable — silently, because
 * the application simply returns something that can never be decrypted.
 *
 * So install is deliberately one-shot and REFUSES to run against an application that
 * already has data.
 *
 * WHY IT CREATES AN ADMINISTRATOR
 *
 * `DemoUserSeeder` refuses outside the local environment, on purpose — it creates
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
 * cPanel → Cron Jobs, a one-off job running this command, then delete the job. It is
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
         * A fresh server reached over FTP has no `storage/framework/views` — the deploy
         * deliberately does not upload anything under `storage/`, because that is where
         * sessions, compiled views and uploaded documents live. Without this directory
         * the application dies with "View path not found", a message that names views
         * rather than the missing folder, and the log is empty because `storage/logs`
         * is missing too.
         */
        $this->ensureStorageTree();

        if ($this->looksInstalled() && ! $this->option('force')) {
            $this->error('This application looks already installed — refusing to run.');
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
            $this->line('APP_KEY already set — left alone.');
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
            $this->line('An administrator already exists — no account created.');

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
         * A password that ships in a repository is a published credential — the sibling
         * project has two live accounts whose password is in the public repo. Generating
         * one means there is nothing to guess and nothing to forget to change before the
         * site is reachable.
         *
         * 24 characters from `Str::password()` is comfortably beyond brute force, and it
         * is printed once and written to the log rather than stored anywhere readable
         * afterwards — the hash is all the database keeps.
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
            'FIRST ADMINISTRATOR CREATED — change this password, then remove this log entry.',
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
     * Create the storage directories the application needs.
     *
     * The deploy does not upload anything under `storage/` — that protects sessions,
     * compiled views and uploaded documents — so on a fresh server these directories do
     * not exist. Missing `storage/framework/views` produces "View path not found",
     * which names views rather than the folder, and missing `storage/logs` means the
     * error cannot be logged, so the log looks empty and innocent.
     */
    private function ensureStorageTree(): void
    {
        $directories = [
            'app/private/attachments',
            'framework/cache/data',
            'framework/sessions',
            'framework/views',
            'logs',
        ];

        foreach ($directories as $directory) {
            $path = storage_path($directory);

            if (! is_dir($path)) {
                mkdir($path, 0755, true);
            }
        }

        $this->line('Storage tree ensured.');
    }
}
