<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Send one real email, to prove the mail configuration works.
 *
 * WHY THIS EXISTS WHEN `MAIL_*` CAN BE SET IN A FORM
 *
 * Because a configuration that LOOKS right and a message that actually arrives are two
 * different things, and nothing in the application distinguishes them. The settings can
 * be wrong in ways that produce no error at all:
 *
 *  - `MAIL_MAILER=log` is a real mailer. It writes to a file, reports success, and
 *    delivers nothing. The setting looks correct in cPanel and in `.env`.
 *  - A wrong `MAIL_FROM_ADDRESS` is frequently accepted, then silently dropped by the
 *    receiving server as a spoofing attempt.
 *  - A shared host often refuses outbound SMTP on 587 while allowing 465, or the reverse.
 *  - A misconfigured SPF record sends the message to the recipient's spam folder, where
 *    the sender never looks.
 *
 * None of those raise anything. They produce silence, and someone concludes the
 * application is broken.
 *
 * WHY THE RESULT IS NOT JUST "IT SENT"
 *
 * `Mail::send()` returning without throwing means the SMTP server ACCEPTED the message. It
 * does not mean it was delivered, and it certainly does not mean it arrived. So this
 * command reports what it knows and states plainly what it cannot know — rather than
 * printing a green tick that the operator will treat as proof.
 */
class TestMail extends Command
{
    protected $signature = 'itrequest:test-mail
                            {to : The address to send to — use an inbox you can actually open}
                            {--force : Send even though ITREQUEST_MAIL_ENABLED is false}';

    protected $description = 'Send one test email and report whether the mail server accepted it';

    public function handle(): int
    {
        $to = (string) $this->argument('to');

        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->line("Not a valid email address: {$to}");

            return self::FAILURE;
        }

        $mailer = (string) config('mail.default');
        $enabled = (bool) config('itrequest.notifications.mail_enabled');

        $this->line('Mailer:      '.$mailer);
        $this->line('Host:        '.config('mail.mailers.smtp.host').':'.config('mail.mailers.smtp.port'));
        $this->line('Scheme:      '.(config('mail.mailers.smtp.scheme') ?: '(none)'));
        $this->line('Username:    '.(config('mail.mailers.smtp.username') ?: '(none)'));
        $this->line('From:        '.config('mail.from.address'));
        $this->line('Deliveries:  '.($enabled ? 'ENABLED' : 'DISABLED (rows stay pending)'));
        $this->newLine();

        /*
         * `log` is refused rather than warned about.
         *
         * It is the default in `.env.example`, so it is what a copy-paste deployment ends
         * up with — and it is the single most misleading configuration possible, because
         * it succeeds. Sending a test that cannot arrive and reporting success would
         * teach the operator the opposite of what they need to know.
         */
        if ($mailer === 'log') {
            $this->line('MAIL_MAILER is "log", which writes the message to a file instead of sending it.');
            $this->line('Nothing will reach an inbox. Set MAIL_MAILER=smtp and the MAIL_HOST, MAIL_PORT,');
            $this->line('MAIL_USERNAME, MAIL_PASSWORD and MAIL_FROM_ADDRESS values from cPanel.');

            return self::FAILURE;
        }

        if ($mailer === 'array') {
            $this->line('MAIL_MAILER is "array", which discards the message. Nothing will be sent.');

            return self::FAILURE;
        }

        /*
         * The application flag is deliberately NOT required.
         *
         * `ITREQUEST_MAIL_ENABLED` controls whether NOTIFICATIONS are delivered. Proving
         * the mail configuration works is the step that happens BEFORE turning that on —
         * requiring it to be on first would mean enabling delivery to find out whether
         * delivery works.
         */
        if (! $enabled && ! $this->option('force')) {
            $this->line('ITREQUEST_MAIL_ENABLED is false, so the application is not delivering notifications yet.');
            $this->line('That is the correct state until this test passes. Sending the test anyway, because');
            $this->line('proving the mail configuration is what has to happen BEFORE enabling delivery.');
            $this->newLine();
        }

        $subject = 'IT Request Management — test message';
        $body = "This is a test message from the IT Request Management system.\n\n"
            ."If you are reading it, the mail configuration works and notifications can be\n"
            ."switched on by setting ITREQUEST_MAIL_ENABLED=true.\n\n"
            .'Sent: '.now()->format('d M Y, H:i')."\n"
            .'From: '.config('app.url');

        try {
            Mail::raw($body, function ($message) use ($to, $subject) {
                $message->to($to)->subject($subject);
            });
        } catch (\Throwable $e) {
            /*
             * The whole exception is printed, not a summary.
             *
             * SMTP errors carry the real cause in their text — "535 authentication failed",
             * "Connection refused", "certificate verify failed". Summarising them into
             * "could not send" throws away the only thing that leads to a fix.
             */
            $this->error('The mail server REJECTED the message.');
            $this->newLine();
            $this->line($e->getMessage());
            $this->newLine();
            $this->line('Common causes, in the order worth checking:');
            $this->line('  1. MAIL_USERNAME must be the FULL address (e.g. you@mwstay.com), not just the part before the @.');
            $this->line('  2. The password is the mailbox password, not the cPanel login.');
            $this->line('  3. Port and scheme must match: 587+TLS or 465+SSL. Mixing them fails.');
            $this->line('  4. The host may block outbound SMTP from the server. Try the other port.');

            return self::FAILURE;
        }

        /*
         * Accepted, not delivered — and the distinction is stated.
         *
         * Claiming "sent successfully" here would be a claim this command cannot support.
         * It knows the server took the message; whether it arrives, and where, is decided
         * downstream by SPF, the spam filter and the recipient.
         */
        $this->info('The mail server ACCEPTED the message.');
        $this->newLine();
        $this->line('That is not the same as delivered. This command cannot see past the server it');
        $this->line('handed the message to. Now check:');
        $this->newLine();
        $this->line("  1. {$to} — including the SPAM folder. A missing SPF record sends a legitimate");
        $this->line('     message to spam, and the sender is the last person to find out.');
        $this->line('  2. storage/logs/laravel.log for a transport warning that did not raise.');
        $this->newLine();

        if (! $enabled) {
            $this->line('Once the message has arrived, set ITREQUEST_MAIL_ENABLED=true and run');
            $this->line('`php artisan itrequest:deploy-check` to confirm.');
        }

        return self::SUCCESS;
    }
}
