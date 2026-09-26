<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Exceptions\IdentityNotConfiguredException;
use App\Services\IdentityManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * The identity provider's callback, and the route that starts a redirect sign-in.
 *
 * WHY THIS IS A CONTROLLER AND NOT A LIVEWIRE COMPONENT
 *
 * A redirect sign-in leaves the application and returns to a URL Microsoft calls. That URL has
 * to be a fixed, registered address — a Livewire component's endpoint is not, and its state is
 * carried in a payload the provider knows nothing about. The callback needs to be a plain route
 * that the app registration can name exactly.
 *
 * THE SESSION SURVIVES THE ROUND TRIP
 *
 * State and nonce are put in the session before the redirect and read from it after. That works
 * because the browser carries the session cookie to Microsoft and back — which is precisely what
 * makes them meaningful, and why they cannot live in the URL instead.
 */
class SsoController extends Controller
{
    public function __construct(private readonly IdentityManager $identity) {}

    /** Send the browser to the identity provider. */
    public function redirect(Request $request): RedirectResponse
    {
        try {
            $provider = $this->identity->provider();
        } catch (IdentityNotConfiguredException $e) {
            /*
             * Back to the sign-in screen with the reason, rather than a 500.
             *
             * A misconfigured provider is an administrator's problem, and a stack trace tells
             * them nothing. The message names the missing variable and where to get it.
             */
            return redirect()->route('login')->withErrors(['email' => 'Single sign-on is not available yet. '.$e->getMessage()]);
        }

        if (! $provider->requiresRedirect()) {
            return redirect()->route('login')
                ->withErrors(['email' => 'This system signs in with an email address and password.']);
        }

        return redirect()->away($provider->redirectUrl());
    }

    /** Complete the round trip. */
    public function callback(Request $request): RedirectResponse
    {
        try {
            $provider = $this->identity->provider();
        } catch (IdentityNotConfiguredException $e) {
            return redirect()->route('login')
                ->withErrors(['email' => 'Single sign-on is not available yet. '.$e->getMessage()]);
        }

        /*
         * EVERY query parameter is passed, not a chosen few.
         *
         * The provider's `userFromCallback()` decides what matters — `code`, `state`, `error`,
         * `error_description`. Filtering here would mean the driver silently never sees an error
         * the caller did not anticipate, and a declined consent would look like a missing code.
         */
        $user = $provider->userFromCallback($request->query());

        if (! $user) {
            /*
             * ONE MESSAGE FOR EVERY FAILURE, AND THE REASON GOES TO THE LOG.
             *
             * The user is told to try again or ask for help. Which of the six things went wrong
             * — state mismatch, expired code, unverified signature, unknown account — is a
             * security distinction: telling someone "no account exists for that address" would
             * let them probe which addresses are real. The log has the detail, because an
             * administrator debugging this needs it and a stranger does not.
             */
            Log::warning('SSO sign-in did not complete.', ['ip' => $request->ip()]);

            return redirect()->route('login')
                ->withErrors(['email' => 'Sign-in could not be completed. Try again, or speak to an administrator.']);
        }

        /*
         * Account state is checked HERE too, not only on the password path.
         *
         * An identity provider will happily authenticate somebody whose account has been
         * deactivated in this application — it does not know about that decision, and it should
         * not. Enforcing it in only one of the two sign-in paths would leave a deactivated
         * account able to sign in through SSO, which is the path most people would use.
         */
        if (! $user->is_active) {
            Log::warning('SSO sign-in refused for a deactivated account.', ['user_id' => $user->id]);

            return redirect()->route('login')
                ->withErrors(['email' => 'This account has been deactivated. Speak to an administrator.']);
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->to($this->landingUrlFor($user));
    }

    /**
     * Where this user should land.
     *
     * The same rule as the password path, deliberately duplicated rather than centralised: it is
     * eight lines, and a shared helper would need a home that both a Livewire component and a
     * controller can reach — which is how a small feature grows a service it does not need. If a
     * third caller appears, that is the moment to extract it.
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
}
