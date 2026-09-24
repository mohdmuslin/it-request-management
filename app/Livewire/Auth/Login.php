<?php

namespace App\Livewire\Auth;

use App\Enums\UserRole;
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

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
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

        $user = Auth::user();

        /*
         * A deactivated account is refused AFTER the credentials check.
         *
         * Checking first would reveal that the address exists. Checking after means
         * a correct password on a disabled account does not get in, and the message
         * says why — which a genuine employee needs, because the alternative is
         * them concluding the system is broken.
         */
        if (! $user->is_active) {
            Auth::logout();

            $this->addError('email', 'This account has been deactivated. Speak to an administrator.');

            return;
        }

        session()->regenerate();

        $this->redirect($this->landingUrlFor($user), navigate: true);
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
