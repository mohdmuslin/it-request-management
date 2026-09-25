<?php

use App\Enums\UserRole;
use App\Models\Department;
use App\Models\Holiday;
use App\Models\Role;
use App\Models\Tier;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\BusinessCalendar;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\Artisan;

/**
 * The deployment commands.
 *
 * These run on a host with no shell, no worker and no way to intervene mid-run, so a
 * mistake here is expensive and hard to see. They are tested because they are exactly
 * the kind of code that is written once and never exercised until it matters.
 */
beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);

    /*
     * A department, because the check REQUIRES one and rightly so.
     *
     * `departments` is a required field in wizard step 1, so an empty list makes the
     * wizard unusable — and it fails at the last step of a form the requestor has
     * already filled in. The first version of these tests omitted it and the check
     * failed, which was the check working.
     */
    Department::create(['code' => 'ICT', 'name' => 'Information Technology']);

    $this->admin = asUser(UserRole::Administrator);
});

// ---- itrequest:deploy-check -------------------------------------------------

it('passes when the essentials are in place', function () {
    app(BusinessCalendar::class)->forgetHolidays();

    $this->artisan('itrequest:deploy-check')->assertSuccessful();
});

it('fails when APP_KEY is missing', function () {
    /*
     * A FAILURE rather than a warning: without a key every session is invalid and
     * anything stored encrypted is unreadable, and the application appears to work
     * until somebody signs in.
     */
    config()->set('app.key', '');

    $this->artisan('itrequest:deploy-check')->assertFailed();
});

it('fails when no administrator exists', function () {
    // Nobody could manage users, reference data or the calendar.
    $adminRole = Role::where('name', UserRole::Administrator->value)->first();

    if ($adminRole) {
        $this->admin->roles()->detach($adminRole->id);
    }

    $this->artisan('itrequest:deploy-check')->assertFailed();
});

it('warns rather than fails when email is off', function () {
    /*
     * The application is entirely usable without email — notifications are recorded
     * with status `pending`, so nothing is lost and delivery can be switched on later.
     * Failing the check over it would make the check unusable while the gate is open.
     */
    config()->set('itrequest.notifications.mail_enabled', false);

    $this->artisan('itrequest:deploy-check')->assertSuccessful();
});

it('fails when email is enabled but the mailer writes to a file', function () {
    /*
     * The specific trap: MAIL_MAILER=log looks configured — it is a real mailer, the
     * settings save, and nothing errors. Nothing reaches an inbox either, and the only
     * symptom is a colleague saying they never got the email.
     */
    config()->set('itrequest.notifications.mail_enabled', true);
    config()->set('mail.default', 'log');

    $this->artisan('itrequest:deploy-check')->assertFailed();
});

it('warns when no public holidays are recorded', function () {
    // Not a failure — the application works — but every due date landing on a holiday
    // will be wrong, and the aging report will show delays nobody could have avoided.
    Holiday::query()->delete();

    $this->artisan('itrequest:deploy-check')->expectsOutputToContain('No public holidays');
});

it('warns when a technical reviewer belongs to no unit', function () {
    /*
     * They hold the role and can reach the screen, but cannot file a recommendation —
     * because the units ASSIGNED to a request decide who reviews it. The symptom they
     * report is "there is nothing for me to do".
     */
    asUser(UserRole::TechnicalReviewer);

    $this->artisan('itrequest:deploy-check')->expectsOutputToContain('cannot file recommendations');
});

it('writes every finding to stdout so a redirect keeps the whole report', function () {
    /*
     * THE DEFECT THIS GUARDS.
     *
     * `$this->warn()` and `$this->error()` write to STDERR. Mixing the two streams meant
     * a redirected report contained the summary — "14 warning(s)" — and none of the 14
     * warnings. On a host with no shell, the log file IS the report, so losing half of
     * it defeats the command's purpose.
     *
     * Asserted by capturing output, which is the channel a cron redirect would keep.
     */
    Artisan::call('itrequest:deploy-check');

    $output = Artisan::output();

    expect($output)->toContain('[ok]')
        // A warning must appear in the captured output, not only on the error stream.
        ->and($output)->toContain('[ ! ]');
});

// ---- itrequest:install ------------------------------------------------------

it('refuses to install over an application that already has users', function () {
    /*
     * Re-running an install regenerates APP_KEY, which invalidates every session and
     * makes any encrypted value permanently unreadable. The refusal is the whole safety
     * mechanism, so it is what gets tested.
     */
    $this->artisan('itrequest:install')
        ->expectsOutputToContain('looks already installed')
        ->assertFailed();
});

it('creates the storage tree before anything else', function () {
    /*
     * First, because everything after it assumes the directories exist. Missing
     * `storage/framework/views` produces "View path not found" — a message that names
     * views rather than the folder — and missing `storage/logs` means that error cannot
     * be logged, so the log looks empty and the application looks innocent.
     */
    $path = storage_path('app/private/attachments');

    $existed = is_dir($path);

    $this->artisan('itrequest:install')->assertFailed();   // refuses, but the tree is ensured first

    expect(is_dir($path))->toBeTrue();

    if (! $existed) {
        // Leave the filesystem as it was found.
        @rmdir($path);
    }
});

it('does not create a second administrator when one exists', function () {
    /*
     * Guards a `--force` re-run from silently replacing a working account's password —
     * which would lock the operator out of the system they were trying to repair.
     */
    $before = User::count();

    $this->artisan('itrequest:install', ['--admin-email' => 'second@example.com']);

    expect(User::where('email', 'second@example.com')->exists())->toBeFalse()
        ->and(User::count())->toBe($before);
});

// ---- itrequest:deploy -------------------------------------------------------

it('runs the deployment steps without touching reference data', function () {
    /*
     * A deploy must be safe to run unattended on every release, so it does nothing
     * destructive if repeated.
     *
     * `--skip-migrate` because the test database is created by RefreshDatabase from the
     * migrations rather than by this command, and running `migrate` here would be
     * testing Laravel's migrator rather than this command's behaviour.
     */
    $tiers = Tier::count();
    $stages = WorkflowStage::count();

    $this->artisan('itrequest:deploy', ['--skip-migrate' => true])->assertSuccessful();

    expect(Tier::count())->toBe($tiers)
        ->and(WorkflowStage::count())->toBe($stages);
});

it('reports problems rather than claiming success', function () {
    /*
     * A deploy that reports success while the site is broken is worse than one that
     * fails: nobody looks. This asserts the verification step actually runs and can
     * fail.
     *
     * The front controller and the Vite manifest are checked because both have gone
     * missing on the sibling project in ways that produced a broken site with a
     * successful-looking deploy — a 403 with no explanation, and a blank page.
     */
    $manifest = public_path('build/manifest.json');
    $backup = $manifest.'.test-backup';

    if (! is_file($manifest)) {
        $this->markTestSkipped('No build manifest in this environment.');
    }

    rename($manifest, $backup);

    try {
        $this->artisan('itrequest:deploy', ['--skip-migrate' => true])->assertFailed();
    } finally {
        rename($backup, $manifest);
    }
});

it('supports a dry run that changes nothing', function () {
    // Needed to inspect a release without committing to it.
    $tiers = Tier::count();

    $this->artisan('itrequest:deploy', ['--dry-run' => true, '--skip-migrate' => true])
        ->assertSuccessful();

    expect(Tier::count())->toBe($tiers);
});

// ---- itrequest:set-password ------------------------------------------------

it('resets a password to a generated value', function () {
    /*
     * The only route to a password reset on this host: no shell, no Terminal, so the
     * password has to be generated and read back from the log.
     */
    $user = asUser(UserRole::Requestor);
    $before = $user->password;

    $this->artisan('itrequest:set-password', ['email' => $user->email, '--keep' => true])
        ->assertSuccessful();

    expect($user->fresh()->password)->not->toBe($before);
});

it('refuses to reset a password for an account that does not exist', function () {
    // Rather than silently creating one, which would be a way into the system.
    $before = User::count();

    $this->artisan('itrequest:set-password', ['email' => 'nobody@example.com', '--keep' => true])
        ->assertFailed();

    expect(User::count())->toBe($before);
});

it('shows account state without changing the password', function () {
    // `--show` exists so an administrator can diagnose "I cannot sign in" without
    // resetting a password somebody may still be using.
    $user = asUser(UserRole::Requestor);
    $before = $user->password;

    $this->artisan('itrequest:set-password', ['email' => $user->email, '--show' => true])
        ->assertSuccessful();

    expect($user->fresh()->password)->toBe($before);
});

it('reports an account that holds no role when showing state', function () {
    /*
     * The commonest cause of "I signed in and there is nothing there" — and the symptom
     * points at the application rather than at the account.
     */
    $user = User::factory()->create(['name' => 'Roleless', 'email' => 'roleless@example.com']);

    $this->artisan('itrequest:set-password', ['email' => $user->email, '--show' => true])
        ->expectsOutputToContain('NONE')
        ->assertSuccessful();
});
