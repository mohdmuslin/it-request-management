<?php

namespace App\Livewire\Auth;

use App\Enums\UserRole;
use App\Exceptions\IdentityNotConfiguredException;
use App\Services\IdentityManager;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Sign in.
 *
 * WHY A LIVEWIRE COMPONENT AND NOT THE SCAFFOLDED AUTH CONTROLLER
 *
 * This application has no public registration, no password reset by email and no
 * email verification — accounts are created by an administrator. The scaffolded
 * controller brings all three and a set of Blade views, most of which would be
 * deleted. One component is less to remove later and keeps the redirect behaviour
 * obvious.
 *
 * THE ONE THING IT DOES THAT MATTERS
 *
 * The redirect after sign-in goes to the role's own landing screen, not to a
 * generic dashboard. An IT HOU arriving at a dashboard of things they cannot act
 * on is a worse first impression than arriving at the work they came to do.
 */
#[Layout('components.layouts.guest')]
class Login extends Component
{
    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    public function mount(): void
    {
        // Already signed in: go straight to the right place rather than showing a
        // form that would immediately be irrelevant.
        if (Auth::check()) {
            $this->redirect($this->landingUrlFor(Auth::user()), navigate: true);
        }
    }

    protected function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    protected function messages(): array
    {
        return [
            'email.required' => 'Enter your email address.',
            'email.email' => 'That does not look like an email address.',
            'password.required' => 'Enter your password.',
        ];
    }

    public function authenticate(): void
    {
        $this->validate();

        /*
         * SIGN-IN GOES THROUGH THE CONFIGURED IDENTITY PROVIDER (FR-001).
         *
         * This used to call `Auth::attempt()` here. The decision is unchanged — `LocalProvider`
         * makes exactly that call — but it now sits behind the contract, so setting
         * `ITREQUEST_IDENTITY_DRIVER=entra` changes the implementation without touching this
         * screen.
         *
         * `fallback()` rather than `provider()`: with SSO selected and an app registration still
         * pending, refusing to serve a login form would turn a configuration decision into an
         * outage for everybody. The misconfiguration is logged rather than hidden.
         */
        $provider = app(IdentityManager::class)->fallback();

        /*
         * A redirect provider cannot be signed into from this form.
         *
         * Saying so beats a failed credential check the user cannot act on: their account is
         * fine, and the button they need is on the page. The message names the situation rather
         * than the cause, because the cause is an administrator's configuration.
         */
        if ($provider->requiresRedirect()) {
            $this->addError('email', 'This system signs in through your organisation account. Use the button above.');

            return;
        }

        $user = $provider->authenticate($this->email, $this->password);

        if (! $user) {
            /*
             * One message for both failure cases, deliberately.
             *
             * Distinguishing "no such account" from "wrong password" would let
             * anyone test whether an address exists. The cost is a slightly less
             * helpful message after a genuine typo; the benefit is not confirming
             * which of several hundred staff addresses are real.
             */
            $this->addError('email', 'Those details do not match our records.');

            // Clear the password so a failed attempt does not leave it in
            // component state, where it would be carried into the next request.
            $this->password = '';

            return;
        }

        /*
         * A deactivated account is refused AFTER the credentials check and BEFORE the session.
         *
         * Checking first would reveal that the address exists. Checking here means a correct
         * password on a disabled account never opens a session at all — the request ends with
         * the user anonymous, which is simpler to reason about than logging in and immediately
         * out. The provider returns a `User` rather than a guard result, so this is the earliest
         * point at which the account's state can be consulted, and it is still before any
         * authenticated state exists.
         *
         * The message says why, because the alternative is a genuine employee concluding the
         * system is broken.
         */
        if (! $user->is_active) {
            $this->password = '';

            $this->addError('email', 'This account has been deactivated. Speak to an administrator.');

            return;
        }

        // Credentials are verified and the account is usable: now open the session.
        Auth::login($user, $this->remember);

        session()->regenerate();

        $this->redirect($this->landingUrlFor($user), navigate: true);
    }

    /**
     * Start a redirect sign-in, or explain why it cannot start.
     *
     * Kept on the component rather than a controller so the error lands in the same place as a
     * failed password, and the user has one screen to look at.
     */
    public function redirectToProvider(): void
    {
        try {
            $provider = app(IdentityManager::class)->provider();
        } catch (IdentityNotConfiguredException $e) {
            /*
             * The problem text is shown, not swallowed.
             *
             * It names the missing environment variable and where to find it. An administrator
             * reading "Entra is not configured" has to guess; reading "ENTRA_TENANT_ID is not
             * set — find it in the Azure portal under App registrations" they do not.
             */
            $this->addError('email', 'Single sign-on is not available yet. '.$e->getMessage());

            return;
        }

        if (! $provider->requiresRedirect()) {
            $this->addError('email', 'This system signs in with an email address and password.');

            return;
        }

        $this->redirect($provider->redirectUrl(), navigate: false);
    }

    /**
     * Where this user should land.
     *
     * Falls back to the dashboard for a user holding no role yet — a normal state
     * for a newly created account awaiting assignment, not an error.
     */
    private function landingUrlFor($user): string
    {
        foreach (UserRole::cases() as $role) {
            if ($user->hasRole($role)) {
                return route($role->landingRoute());
            }
        }

        return route('dashboard');
    }

    public function render(): View
    {
        return view('livewire.auth.login');
    }
}
