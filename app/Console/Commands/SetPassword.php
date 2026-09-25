<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Set a user's password and print it, then self-destruct.
 *
 * WHY THIS EXISTS ON A HOST WITH NO SHELL
 *
 * There is no cPanel Terminal and no SSH, so a forgotten password cannot be reset by
 * editing a file or running a one-off command interactively. The only route in is a
 * cron job — and a cron job cannot type a password, so the password has to be
 * generated here and read back from the log.
 *
 * WHY IT SELF-DELETES
 *
 * A password-reset script left on a server is a back door that outlives its purpose.
 * This one removes itself after a successful run, so a copy forgotten on the host
 * cannot be used to take an account over later. The alternative — remembering to
 * delete it — is the step that gets skipped on a busy morning.
 *
 * It refuses outside a live environment because deleting its own file during local
 * development is actively unhelpful: you would have to restore it from git every time
 * you used it.
 */
class SetPassword extends Command
{
    protected $signature = 'itrequest:set-password
                            {email : The account to reset}
                            {--show : Print the password without changing it}
                            {--keep : Do not delete this script afterwards}';

    protected $description = 'Reset a user password to a generated value and print it (self-deletes afterwards)';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error("No account with the email {$this->argument('email')}.");

            return self::FAILURE;
        }

        if ($this->option('show')) {
            $this->line("{$user->name} <{$user->email}>");
            $this->line('Active: '.($user->is_active ? 'yes' : 'NO — they cannot sign in'));
            $this->line('Roles:  '.($user->roles->pluck('name')->join(', ') ?: 'NONE — they can reach almost nothing'));

            return self::SUCCESS;
        }

        $password = Str::password(24);

        $user->forceFill(['password' => $password])->save();

        /*
         * Printed AND logged.
         *
         * Cron output goes to /dev/null by default, so the printed value is frequently
         * lost. The log is readable through cPanel's File Manager, which is the only
         * interface this host offers.
         */
        $this->newLine();
        $this->line("  Email:    {$user->email}");
        $this->line("  Password: {$password}");
        $this->newLine();

        Log::warning(
            'PASSWORD RESET — change it after signing in, then remove this log entry.',
            ['email' => $user->email, 'password' => $password],
        );

        $this->warn('This password is shown once. It is also in storage/logs/laravel.log.');

        if (! $this->option('keep')) {
            $this->selfDestruct();
        }

        return self::SUCCESS;
    }

    /**
     * Remove this script.
     *
     * Never fails the command: the password has already been set, and reporting a
     * delete failure as a command failure would suggest the reset did not happen.
     */
    private function selfDestruct(): void
    {
        $path = __FILE__;

        if (@unlink($path)) {
            $this->line('This script has deleted itself. It cannot be run again.');

            return;
        }

        /*
         * Could not delete. Said loudly, because a leftover reset script is a back door
         * — and `__FILE__` inside a compiled or Phar context can resolve somewhere
         * unexpected, so the actual path is printed to make manual removal simple.
         */
        $this->warn("Could not delete this script automatically. Remove it by hand: {$path}");
    }
}
