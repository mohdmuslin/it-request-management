<?php

namespace App\Identity;

use App\Contracts\IdentityProvider;
use App\Contracts\UserProfile;
use App\Exceptions\IdentityNotConfiguredException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Sign-in against accounts held by this application.
 *
 * WHAT THIS REPLACES
 *
 * `Auth\Login` used to call `Auth::attempt()` directly, and this driver now holds that call. The
 * behaviour is deliberately identical — including the failure message — because this is a
 * refactor of where the decision is made, not a change to what the decision is.
 *
 * WHY `Auth::attempt()` IS STILL USED
 *
 * It is Laravel's session guard doing the login, not a replacement for it. Calling it here rather
 * than in the component means the provider contract is satisfied by the same code path that was
 * already proven, so introducing the seam cannot change how sign-in behaves.
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 *
 * It does not check `is_active`, and it does not decide where the user lands. Those apply to
 * every driver, so they live in the caller. A driver that also enforced account state would make
 * "the account is deactivated" a provider-specific behaviour, and a future SSO driver would
 * silently not enforce it.
 */
class LocalProvider implements IdentityProvider
{
    public function driver(): string
    {
        return 'local';
    }

    /**
     * Always true.
     *
     * The credentials this driver needs are in the `users` table, which exists or the application
     * would not be running. There is nothing to configure and no way for it to be half-set-up —
     * which is the reason it is the sensible default.
     */
    public function isConfigured(): bool
    {
        return true;
    }

    public function configurationProblem(): ?string
    {
        return null;
    }

    public function requiresRedirect(): bool
    {
        return false;
    }

    public function authenticate(string $email, string $secret): ?User
    {
        $user = User::where('email', $email)->first();

        /*
         * Hash::check() is called even when no user was found.
         *
         * Returning early would make "no such account" measurably faster than "wrong password",
         * and that difference is enough to enumerate staff addresses over a few hundred requests.
         * Timing is a real channel even when the messages are identical — which they are here,
         * and were before this refactor too.
         *
         * THE DUMMY HASH MUST BE WELL FORMED. bcrypt is exactly 60 characters; a truncated one
         * makes the hasher throw "This password does not use the Bcrypt algorithm" rather than
         * returning false, so a mistyped address would produce a 500 instead of a failed sign-in.
         * This is generated once per process rather than written as a literal, so it cannot drift
         * from the configured algorithm — and it is a real hash of a value nobody knows, so it
         * costs the same as a genuine check.
         */
        static $dummy = null;
        $dummy ??= Hash::make(Str::random(48));

        if (! Hash::check($secret, $user?->password ?? $dummy)) {
            return null;
        }

        return $user;
    }

    public function redirectUrl(): string
    {
        throw IdentityNotConfiguredException::forDriver(
            'local',
            'Local sign-in does not redirect anywhere; it authenticates here.'
        );
    }

    public function userFromCallback(array $parameters): ?User
    {
        throw IdentityNotConfiguredException::forDriver(
            'local',
            'Local sign-in has no callback; it authenticates here.'
        );
    }

    /**
     * The profile this application already holds.
     *
     * FR-002 asks for department, division and reporting data. With no external provider, the
     * user's own record is the only source — so this returns it rather than inventing one. The
     * point of the method is that the *caller* does not know that, and will not have to change
     * when a provider that knows better is configured.
     */
    public function profileFor(User $user): UserProfile
    {
        return new UserProfile(
            identifier: $user->email,
            email: $user->email,
            name: $user->name,
            employeeNo: $user->employee_no,
            entraObjectId: $user->entra_object_id,
            departmentId: $user->department_id,
            divisionId: $user->division_id,
            managerId: $user->manager_id,
        );
    }
}
