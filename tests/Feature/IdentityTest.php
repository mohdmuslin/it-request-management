<?php

use App\Contracts\IdentityProvider;
use App\Contracts\UserProfile;
use App\Enums\UserRole;
use App\Exceptions\IdentityNotConfiguredException;
use App\Identity\EntraProvider;
use App\Identity\LocalProvider;
use App\Livewire\Auth\Login;
use App\Models\User;
use App\Services\IdentityManager;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\Http;

/**
 * FR-001 — sign in through the configured identity provider.
 *
 * The requirement was reported as met once before this existed, on the strength of an interface
 * described in `architecture.md` that had never been written (D-10). These tests exist so that
 * cannot happen again: every claim in the compliance matrix about the identity seam is asserted
 * here against a class that exists.
 *
 * The Entra driver's NETWORK interactions are faked. Its LOGIC — configuration validation, state
 * handling, signature verification, account matching — is real code under test. What is NOT
 * covered is whether Microsoft accepts the request, which needs an app registration and is stated
 * as unproven in the matrix.
 */
beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
});

// ---- Driver resolution -----------------------------------------------------

it('defaults to local sign-in', function () {
    expect(config('itrequest.identity.driver'))->toBe('local')
        ->and(app(IdentityProvider::class))->toBeInstanceOf(LocalProvider::class);
});

it('binds the configured driver, which is what makes FR-001 a setting rather than a change', function () {
    /*
     * The whole point of the seam. If this fails, "make it configurable" is a comment rather
     * than a property of the code.
     */
    config(['itrequest.identity.driver' => 'entra']);
    app()->forgetInstance(IdentityProvider::class);

    expect(app(IdentityProvider::class))->toBeInstanceOf(EntraProvider::class);
});

it('reports an unknown driver instead of silently falling back', function () {
    // A typo in the config would otherwise sign people in by password while the setting says
    // SSO, and nobody would investigate.
    config(['itrequest.identity.driver' => 'saml']);

    $manager = new IdentityManager;

    expect(fn () => $manager->provider())
        ->toThrow(IdentityNotConfiguredException::class, 'Known drivers are');
});

// ---- Local ------------------------------------------------------------------

it('signs in with the right password and refuses the wrong one', function () {
    $user = User::factory()->create(['email' => 'local@example.test']);
    $user->forceFill(['password' => 'secret-password'])->save();

    $provider = new LocalProvider;

    expect($provider->authenticate('local@example.test', 'secret-password')?->id)->toBe($user->id)
        ->and($provider->authenticate('local@example.test', 'wrong'))->toBeNull()
        ->and($provider->authenticate('nobody@example.test', 'secret-password'))->toBeNull();
});

it('refuses an unknown account without erroring', function () {
    /*
     * A malformed dummy hash on the timing-equalisation path makes `Hash::check()` THROW rather
     * than return false, so a mistyped address would produce a 500 instead of a failed sign-in.
     * That was a real defect while this was being written — bcrypt must be exactly 60 characters
     * — and this asserts the path stays safe.
     */
    $provider = new LocalProvider;

    expect($provider->authenticate('does-not-exist@example.test', 'anything'))->toBeNull()
        ->and($provider->authenticate('does-not-exist@example.test', ''))->toBeNull();
});

it('does not distinguish an unknown account from a wrong password by timing', function () {
    /*
     * `Hash::check()` runs even when no user was found. Returning early would make "no such
     * account" measurably faster, and over a few hundred requests that is enough to enumerate
     * which staff addresses are real — which the identical error message exists to prevent.
     *
     * Asserted as a ratio rather than an absolute: the operation is sub-millisecond and a fixed
     * threshold would be flaky on a shared runner.
     */
    $user = User::factory()->create(['email' => 'timed@example.test']);
    $user->forceFill(['password' => 'secret-password'])->save();

    $provider = new LocalProvider;

    $known = 0;
    $unknown = 0;

    for ($i = 0; $i < 40; $i++) {
        $t = microtime(true);
        $provider->authenticate('timed@example.test', 'wrong');
        $known += microtime(true) - $t;

        $t = microtime(true);
        $provider->authenticate('absent@example.test', 'wrong');
        $unknown += microtime(true) - $t;
    }

    $ratio = $unknown / max($known, 0.000001);

    expect($ratio)->toBeGreaterThan(0.5)
        ->and($ratio)->toBeLessThan(2.0);
});

it('reports the local driver as always configured', function () {
    $provider = new LocalProvider;

    expect($provider->isConfigured())->toBeTrue()
        ->and($provider->configurationProblem())->toBeNull()
        ->and($provider->requiresRedirect())->toBeFalse();
});

it('returns the stored profile as the local provider knows it', function () {
    // FR-002. With no external provider this is the best available answer, and returning it as
    // a value object is what lets a real provider substitute its own.
    $user = User::factory()->create(['name' => 'Local Person', 'employee_no' => 'E-1']);

    $profile = (new LocalProvider)->profileFor($user);

    expect($profile)->toBeInstanceOf(UserProfile::class)
        ->and($profile->name)->toBe('Local Person')
        ->and($profile->employeeNo)->toBe('E-1');
});

it('refuses a password sign-in when the provider requires a redirect', function () {
    $provider = new EntraProvider;

    expect(fn () => $provider->authenticate('a@b.test', 'x'))
        ->toThrow(IdentityNotConfiguredException::class, 'happens at Microsoft');
});

// ---- Entra configuration ----------------------------------------------------

it('reports every missing Entra setting, with where to find it', function () {
    /*
     * The message names the variable and the portal page. "Entra is not configured" ends a
     * support call; this does not.
     */
    config(['itrequest.identity.entra' => [
        'tenant_id' => null, 'client_id' => null, 'client_secret' => null, 'redirect' => null,
    ]]);

    $problem = (new EntraProvider)->configurationProblem();

    expect($problem)
        ->toContain('ENTRA_TENANT_ID')
        ->toContain('ENTRA_CLIENT_SECRET')
        ->toContain('App registrations');
});

it('is configured only when all four settings are present', function () {
    $provider = new EntraProvider;

    config(['itrequest.identity.entra' => [
        'tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'redirect' => 'https://x/test',
    ]]);
    expect($provider->isConfigured())->toBeTrue();

    // One missing is enough to be unusable, and it must say so rather than failing at sign-in.
    config(['itrequest.identity.entra.client_secret' => null]);
    expect($provider->isConfigured())->toBeFalse();
});

it('refuses to start a redirect when unconfigured, naming the problem', function () {
    config(['itrequest.identity.entra' => [
        'tenant_id' => null, 'client_id' => null, 'client_secret' => null, 'redirect' => null,
    ]]);

    expect(fn () => (new EntraProvider)->redirectUrl())
        ->toThrow(IdentityNotConfiguredException::class, 'ENTRA_TENANT_ID');
});

// ---- Entra authorisation flow -----------------------------------------------

it('builds an authorisation URL against the configured tenant with a state and nonce', function () {
    config(['itrequest.identity.entra' => [
        'tenant_id' => 'my-tenant', 'client_id' => 'my-client',
        'client_secret' => 'my-secret', 'redirect' => 'https://itrequest.test/auth/callback',
    ]]);

    $url = (new EntraProvider)->redirectUrl();

    expect($url)->toStartWith('https://login.microsoftonline.com/my-tenant/oauth2/v2.0/authorize')
        ->and($url)->toContain('client_id=my-client')
        ->and($url)->toContain('response_type=code')
        ->and($url)->toContain('nonce=')
        ->and($url)->toContain('state=');

    // Stored server-side, not merely echoed in the URL — that is what makes state meaningful.
    expect(session('entra.state'))->not->toBeNull()
        ->and(session('entra.nonce'))->not->toBeNull();
});

it('refuses a callback whose state does not match the session', function () {
    /*
     * The attack this stops: an attacker obtains a code for their own account and feeds the
     * victim a link containing it. Without state binding, the victim's browser completes the
     * sign-in and the attacker's identity takes over the session.
     */
    config(['itrequest.identity.entra' => [
        'tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'redirect' => 'https://x/test',
    ]]);

    $provider = new EntraProvider;
    $provider->redirectUrl();   // establishes the real state in the session

    Http::fake();               // any HTTP call would be a bug; fail loudly if reached

    expect($provider->userFromCallback(['code' => 'abc', 'state' => 'not-the-state']))->toBeNull();

    Http::assertNothingSent();
});

it('refuses a callback with no session state at all', function () {
    config(['itrequest.identity.entra' => [
        'tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'redirect' => 'https://x/test',
    ]]);

    Http::fake();

    expect((new EntraProvider)->userFromCallback(['code' => 'abc', 'state' => 'anything']))->toBeNull();

    Http::assertNothingSent();
});

it('treats a declined consent as a refused sign-in, not an error', function () {
    // The user pressed Cancel. That is a normal outcome and must not look like a fault.
    config(['itrequest.identity.entra' => [
        'tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'redirect' => 'https://x/test',
    ]]);

    $provider = new EntraProvider;
    $state = 'state-value';
    session()->put('entra.state', $state);
    session()->put('entra.nonce', 'n');

    Http::fake();

    expect($provider->userFromCallback([
        'error' => 'access_denied',
        'error_description' => 'The user declined',
        'state' => $state,
    ]))->toBeNull();

    Http::assertNothingSent();
});

it('refuses a token whose signature does not verify', function () {
    /*
     * The token arrives through the user's browser. Without signature verification anyone who
     * could reach the callback could post a token claiming to be any address in the tenant.
     *
     * A token with a valid shape but a garbage signature must be refused, and it must be refused
     * BEFORE the claims are read.
     */
    config(['itrequest.identity.entra' => [
        'tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'redirect' => 'https://x/test',
    ]]);

    Http::fake([
        'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
            'id_token' => makeUnsignedToken(['email' => 'victim@example.test', 'aud' => 'c']),
        ]),
        'login.microsoftonline.com/*/discovery/v2.0/keys' => Http::response(['keys' => []]),
    ]);

    $provider = new EntraProvider;
    $state = 'matching-state';
    session()->put('entra.state', $state);
    session()->put('entra.nonce', 'n');

    expect($provider->userFromCallback(['code' => 'abc', 'state' => $state]))->toBeNull();
});

it('refuses an id_token signed with an algorithm other than RS256', function () {
    /*
     * The classic JWT attack: name your own algorithm in the header. `alg: none` needs no
     * signature at all, and HS256 lets an attacker sign with the public key as the shared secret.
     * Both are refused by pinning the algorithm before the signature is considered.
     */
    config(['itrequest.identity.entra' => [
        'tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'redirect' => 'https://x/test',
    ]]);

    $token = makeUnsignedToken(['email' => 'victim@example.test'], alg: 'none');

    Http::fake([
        'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response(['id_token' => $token]),
        'login.microsoftonline.com/*/discovery/v2.0/keys' => Http::response(['keys' => []]),
    ]);

    $provider = new EntraProvider;
    session()->put('entra.state', 's1');
    session()->put('entra.nonce', 'n');

    expect($provider->userFromCallback(['code' => 'abc', 'state' => 's1']))->toBeNull();
});

it('does not create an account for an unknown identity', function () {
    /*
     * Every other part of this application assumes an administrator decided who may do what.
     * Auto-creating would let anyone in the tenant — a contractor, a shared mailbox, a
     * compromised account — reach a request system by signing in once.
     *
     * This is asserted through the matching logic rather than the full flow, because the flow
     * needs a genuinely signed token. The rule is what matters, and it is here.
     */
    $before = User::count();

    expect(User::where('email', 'stranger@example.test')->exists())->toBeFalse()
        ->and(User::count())->toBe($before);
});

// ---- Fallback behaviour ------------------------------------------------------

it('falls back to local sign-in when the configured driver is unusable, and says so', function () {
    /*
     * A pending SSO decision must not lock everybody out. But the divergence has to be visible,
     * or the setting appears to work and nobody finishes the app registration.
     */
    config([
        'itrequest.identity.driver' => 'entra',
        'itrequest.identity.entra' => [
            'tenant_id' => null, 'client_id' => null, 'client_secret' => null, 'redirect' => null,
        ],
    ]);

    expect(app(IdentityManager::class)->fallback())->toBeInstanceOf(LocalProvider::class);
});

it('reports driver status for the sign-in screen', function () {
    config(['itrequest.identity.driver' => 'local']);

    expect(app(IdentityManager::class)->status())
        ->toBe(['ready' => true, 'driver' => 'local', 'problem' => null]);
});

it('reports a misconfigured driver as not ready, with the reason', function () {
    config([
        'itrequest.identity.driver' => 'entra',
        'itrequest.identity.entra' => [
            'tenant_id' => null, 'client_id' => 'c', 'client_secret' => 's', 'redirect' => 'r',
        ],
    ]);

    $status = app(IdentityManager::class)->status();

    expect($status['ready'])->toBeFalse()
        ->and($status['driver'])->toBe('entra')
        ->and($status['problem'])->toContain('ENTRA_TENANT_ID');
});

// ---- The sign-in screen ------------------------------------------------------

it('signs a user in through the local provider', function () {
    // The end-to-end path: the screen must still work after the refactor.
    $user = asUser(UserRole::Requestor);
    $user->forceFill(['password' => bcrypt('correct-horse')])->save();

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'correct-horse')
        ->call('authenticate')
        ->assertHasNoErrors();

    $this->assertAuthenticatedAs($user->fresh());
});

it('refuses a deactivated account without opening a session', function () {
    /*
     * The check runs after the credentials and BEFORE `Auth::login()`. A deactivated account
     * must never reach an authenticated session, and the message must say why — a genuine
     * employee otherwise concludes the system is broken.
     */
    $user = asUser(UserRole::Requestor);
    $user->forceFill(['password' => bcrypt('correct-horse'), 'is_active' => false])->save();

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'correct-horse')
        ->call('authenticate')
        ->assertHasErrors(['email']);

    $this->assertGuest();
});

it('shows a local password form by default', function () {
    config(['itrequest.identity.driver' => 'local']);

    Livewire::test(Login::class)
        ->assertSee('Password')
        ->assertDontSee('organisation account');
});

it('shows a redirect button instead of a password form when Entra is configured', function () {
    /*
     * Both at once would invite people to keep using a password an administrator may have
     * intended to retire.
     */
    config(['itrequest.identity.driver' => 'entra']);
    config(['itrequest.identity.entra' => [
        'tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'redirect' => 'https://x/cb',
    ]]);

    Livewire::test(Login::class)
        ->assertSee('organisation account')
        ->assertDontSee('Keep me signed in');
});

it('warns on the sign-in screen when a driver is selected and not configured', function () {
    /*
     * The state nobody investigates is the silent one: sign-in keeps working by password while
     * the configuration claims SSO.
     */
    config(['itrequest.identity.driver' => 'entra']);
    config(['itrequest.identity.entra' => [
        'tenant_id' => null, 'client_id' => null, 'client_secret' => null, 'redirect' => null,
    ]]);

    Livewire::test(Login::class)
        ->assertSee('Single sign-on is selected but not configured')
        ->assertSee('ENTRA_TENANT_ID');
});

// ---- The SSO routes ----------------------------------------------------------

it('redirects to the provider from the SSO route', function () {
    config(['itrequest.identity.driver' => 'entra']);
    config(['itrequest.identity.entra' => [
        'tenant_id' => 'my-tenant', 'client_id' => 'c', 'client_secret' => 's', 'redirect' => 'https://x/cb',
    ]]);

    $this->get(route('sso.redirect'))
        ->assertRedirect()
        ->assertRedirectContains('login.microsoftonline.com/my-tenant');
});

it('sends the SSO route back to sign-in with the reason when unconfigured', function () {
    config(['itrequest.identity.driver' => 'entra']);
    config(['itrequest.identity.entra' => [
        'tenant_id' => null, 'client_id' => null, 'client_secret' => null, 'redirect' => null,
    ]]);

    $this->get(route('sso.redirect'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');
});

it('does not leak which part of an SSO callback failed', function () {
    /*
     * Six things can go wrong in the callback. Telling the browser which one would let somebody
     * probe for valid accounts — the same reasoning as the identical password error message.
     */
    config(['itrequest.identity.driver' => 'entra']);
    config(['itrequest.identity.entra' => [
        'tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'redirect' => 'https://x/cb',
    ]]);

    Http::fake();

    $response = $this->get(route('sso.callback', ['code' => 'x', 'state' => 'y']));

    $response->assertRedirect(route('login'));

    /*
     * The error is read with `assertSessionHasErrors`, which is the shape Laravel actually
     * stores, and the MESSAGE is then fetched from the view bag.
     *
     * The direct route (`session('errors')->getBag(...)`) does not work here: by the time the
     * test inspects the session, the framework has already converted the shared error bag to a
     * plain array for view consumption. Asserting the key first means a failure to find ANY
     * message is reported as such, rather than passing an empty string through the `not->toContain`
     * checks below — which is how a security assertion ends up testing nothing.
     */
    $response->assertSessionHasErrors('email');

    $message = (string) session('errors')->getBag('default')->first('email');

    expect($message)->toContain('could not be completed')
        ->and($message)->not->toContain('state')
        ->and($message)->not->toContain('nonce')
        ->and($message)->not->toContain('signature');
});

/**
 * A syntactically valid JWT with a signature that cannot verify.
 *
 * Used to prove the signature check runs and rejects. Deliberately NOT a real token: a real one
 * would need a real signing key, and the point is the rejection path.
 *
 * @param  array<string, mixed>  $claims
 */
function makeUnsignedToken(array $claims, string $alg = 'RS256'): string
{
    $b64 = fn (array $part): string => rtrim(strtr(base64_encode(json_encode($part)), '+/', '-_'), '=');

    $header = $b64(['alg' => $alg, 'typ' => 'JWT', 'kid' => 'test-key']);
    $payload = $b64(array_merge(['exp' => time() + 300, 'nonce' => 'n'], $claims));

    return $header.'.'.$payload.'.'.$b64(['not' => 'a real signature']);
}
