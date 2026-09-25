<?php

use App\Enums\UserRole;
use App\Models\Department;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\Mail;

/**
 * The mail test command — the Phase C gate.
 *
 * Four required features depend on delivery (assignment notification, decision
 * notification, reminders, escalation), and the whole gate rests on a claim that is easy
 * to fake: "mail is configured". These tests exist because the configurations that fail
 * all LOOK correct, and the command's job is to refuse to imply otherwise.
 */
beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
    Department::create(['code' => 'ICT', 'name' => 'Information Technology']);
    asUser(UserRole::Administrator);
});

it('refuses to test the log mailer rather than reporting success', function () {
    /*
     * THE MOST IMPORTANT TEST HERE.
     *
     * `log` is the default in `.env.example`, so it is what a copy-paste deployment ends
     * up with — and it is the most misleading configuration possible, because it
     * SUCCEEDS. A test that sent a message which could never arrive and reported success
     * would teach the operator the opposite of what they need to know.
     */
    config()->set('mail.default', 'log');

    $this->artisan('itrequest:test-mail', ['to' => 'someone@example.com'])
        ->expectsOutputToContain('writes the message to a file instead of sending it')
        ->assertFailed();
});

it('refuses the array mailer, which discards the message', function () {
    config()->set('mail.default', 'array');

    $this->artisan('itrequest:test-mail', ['to' => 'someone@example.com'])
        ->expectsOutputToContain('discards the message')
        ->assertFailed();
});

it('rejects an address that is not an email', function () {
    // Sending to a malformed address would fail at the server with a message about the
    // server, rather than about the input.
    config()->set('mail.default', 'smtp');

    $this->artisan('itrequest:test-mail', ['to' => 'not-an-address'])
        ->expectsOutputToContain('Not a valid email address')
        ->assertFailed();
});

it('sends when the mailer is real', function () {
    // The success path, faked at the transport so no network is involved.
    config()->set('mail.default', 'smtp');
    Mail::fake();

    $this->artisan('itrequest:test-mail', ['to' => 'someone@example.com'])
        ->assertSuccessful();
});

it('sends even though notifications are disabled, because proving mail is a prior step', function () {
    /*
     * `ITREQUEST_MAIL_ENABLED` controls whether NOTIFICATIONS are delivered. Proving the
     * mail configuration works is the step that happens BEFORE turning that on —
     * requiring it first would mean enabling delivery to find out whether delivery works.
     */
    config()->set('mail.default', 'smtp');
    config()->set('itrequest.notifications.mail_enabled', false);
    Mail::fake();

    $this->artisan('itrequest:test-mail', ['to' => 'someone@example.com'])
        ->expectsOutputToContain('correct state until this test passes')
        ->assertSuccessful();
});

it('reports that the server accepted the message, not that it was delivered', function () {
    /*
     * A claim this command cannot support.
     *
     * It knows the SMTP server took the message. Whether it arrives, and where, is decided
     * downstream by SPF, the spam filter and the recipient. Printing "sent successfully"
     * would be treated as proof by whoever reads the log.
     */
    config()->set('mail.default', 'smtp');
    Mail::fake();

    $this->artisan('itrequest:test-mail', ['to' => 'someone@example.com'])
        ->expectsOutputToContain('ACCEPTED the message')
        ->expectsOutputToContain('not the same as delivered')
        ->assertSuccessful();
});

// ---- The deploy check reflects the flag -------------------------------------

it('reports email as a warning while delivery is disabled', function () {
    // Not a failure: the application is fully usable, notifications are recorded, and
    // nothing is lost — delivery can be switched on later.
    config()->set('itrequest.notifications.mail_enabled', false);

    $this->artisan('itrequest:deploy-check')
        ->expectsOutputToContain('Email delivery is OFF')
        ->assertSuccessful();
});

it('fails the deploy check when email is on but the mailer discards messages', function () {
    /*
     * The trap the flag-alone check would miss: `ITREQUEST_MAIL_ENABLED=true` with
     * `MAIL_MAILER=log` means the application believes it is notifying people, records
     * rows as `sent`... no — it means it attempts delivery into a file, reports success,
     * and nobody is told anything.
     */
    config()->set('itrequest.notifications.mail_enabled', true);
    config()->set('mail.default', 'log');

    $this->artisan('itrequest:deploy-check')
        ->expectsOutputToContain('writes to a file instead of sending')
        ->assertFailed();
});

it('passes the email check when delivery is enabled and the mailer is real', function () {
    config()->set('itrequest.notifications.mail_enabled', true);
    config()->set('mail.default', 'smtp');

    $this->artisan('itrequest:deploy-check')
        ->expectsOutputToContain("Email is enabled via the 'smtp' mailer")
        ->assertSuccessful();
});
