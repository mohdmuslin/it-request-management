<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Create an account from cron, for when the administrator screen cannot be reached.
 *
 * WHY THIS EXISTS WHEN THE ADMINISTRATION SCREEN ALREADY CREATES ACCOUNTS
 *
 * Because the screen is behind `auth`, and the situation this command exists for is the
 * one where nobody can sign in. A fresh install handled by `itrequest:install` creates
 * ONE administrator; if that password is lost, or the account is deactivated, or the
 * administrator leaves without handing over, the Users screen is unreachable and there
 * is no route back in except SQL — and on this host SQL means phpMyAdmin over a
 * session anybody with the cPanel password can open.
 *
 * It is also the answer to "we are loading 40 staff on Friday", where typing into a
 * form forty times is slow and error-prone.
 *
 * WHY THE PASSWORD IS GENERATED AND NOT AN ARGUMENT
 *
 * A password passed as a command argument lands in the shell history, in the process
 * list, and in any cron log that records the command line. This host has no shell, so
 * cron is the only route in — and cron mail and logs are exactly where an argument
 * would be written down. Generated here and printed, it is never in a place that
 * records commands.
 *
 * WHY ROLES CANNOT BE GRANTED ON A TICK
 *
 * `--role` exists, and it exists so a batch load does not need a second pass through
 * the browser. It is deliberately opt-in: the default creates an account that can sign
 * in and reach almost nothing, and reaching for escalation has to be a decision
 * somebody types rather than a default that arrives with the account.
 */
class MakeUser extends Command
{
    protected $signature = 'itrequest:make-user
                            {email : The email address, which is also the sign-in name}
                            {--name= : Display name (defaults to the part before the @)}
                            {--employee-no= : Optional employee number}
                            {--role=* : Roles to grant, repeatable — e.g. --role=requestor}
                            {--password= : Use a specific password instead of a generated one}';

    protected $description = 'Create an account and print its password (for when nobody can sign in)';

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        /*
         * Checked before anything else.
         *
         * `users.email` is unique, so without this the insert fails with a database
         * error naming a constraint — which on a cron job means an email to nobody and
         * a log entry that reads like a crash rather than a mistyped address.
         */
        if (User::where('email', $email)->exists()) {
            $this->error("An account already exists with the email {$email}.");

            return self::FAILURE;
        }

        $roles = $this->option('role');
        $valid = array_column(UserRole::cases(), 'value');
        $unknown = array_diff($roles, $valid);

        if ($unknown !== []) {
            $this->error('Unknown role(s): '.implode(', ', $unknown));
            $this->newLine();
            $this->line('Valid roles are:');
            $this->line('  '.implode("\n  ", $valid));

            return self::FAILURE;
        }

        $password = (string) ($this->option('password') ?: Str::password(24));

        $user = User::create([
            'name' => $this->option('name') ?: Str::of($email)->before('@')->replace(['.', '_', '-'], ' ')->title()->toString(),
            'email' => $email,
            'employee_no' => $this->option('employee-no') ?: null,
            'password' => $password,
            'is_active' => true,
        ]);

        if ($roles !== []) {
            $ids = Role::whereIn('name', $roles)->pluck('id', 'name');
            $sync = [];

            /*
             * `granted_at` is stamped because the pivot carries it and the audit trail
             * reads it to answer "when were they made an administrator?". Attaching
             * without it leaves a null where a timestamp belongs.
             */
            foreach ($roles as $name) {
                if (isset($ids[$name])) {
                    $sync[$ids[$name]] = ['granted_at' => now(), 'granted_by' => null];
                }
            }

            $user->roles()->sync($sync);
        }

        app(AuditService::class)->record(
            event: 'user.created',
            subject: $user,
            old: null,
            new: ['name' => $user->name, 'email' => $user->email, 'roles' => $roles, 'source' => 'command'],
        );

        $this->newLine();
        $this->info('Account created.');
        $this->line("  Name:   {$user->name}");
        $this->line("  Email:  {$user->email}");
        $this->line('  Roles:  '.($roles === [] ? 'NONE — they can sign in and reach almost nothing' : implode(', ', $roles)));
        $this->line("  Password: {$password}");
        $this->newLine();
        $this->warn('This password is shown once. It is also in storage/logs/laravel.log.');

        if ($roles === []) {
            $this->line('Grant a role before they can do anything: php artisan itrequest:make-user --help');
        }

        /*
         * Logged as well as printed, because this host runs commands from cron and cron
         * output is frequently discarded. Without the log line the password would exist
         * nowhere and the account would be locked from the moment it was created.
         *
         * Never fails the command: the account is already created, and reporting the log
         * as an error would suggest the creation failed.
         */
        try {
            Log::warning('ACCOUNT CREATED FROM COMMAND — change the password after signing in.', [
                'email' => $user->email,
                'password' => $password,
            ]);
        } catch (\Throwable $e) {
            $this->warn('Could not write to the log, so the password above is the only copy.');
        }

        return self::SUCCESS;
    }
}
