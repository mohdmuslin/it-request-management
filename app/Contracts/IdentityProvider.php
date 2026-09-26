<?php

namespace App\Contracts;

use App\Exceptions\IdentityNotConfiguredException;
use App\Models\User;

/**
 * What an identity provider must be able to do.
 *
 * WHY THIS INTERFACE EXISTS
 *
 * The brief requires sign-in through approved enterprise identity and SSO (FR-001). The POC
 * authenticates locally because an app registration, tenant admin consent and a token-refresh
 * cycle are provisioning steps that demonstrate no business process — and the POC exists to
 * demonstrate the process.
 *
 * The decision on which provider the organisation will use is outstanding. So the point of this
 * interface is that **answering that question must not require a code change**: it is a config
 * value, and a driver implementation. That is what makes the POC a usable answer to "what would
 * this look like with SSO?" rather than a description of one.
 *
 * TWO KINDS OF SIGN-IN, ONE CONTRACT
 *
 * A password provider authenticates in this application: the user types credentials, and the
 * application decides. A redirect provider authenticates at the identity provider and comes back
 * with a result. They are genuinely different flows, and an interface that pretended otherwise
 * would be implemented by one of them as a lie.
 *
 * So the contract models both, and `requiresRedirect()` is how the caller finds out which it is
 * dealing with. The password paths on a redirect provider are not reachable — the caller asks
 * first — and they throw rather than returning null, because returning null would read as
 * "wrong password" when the real answer is "you called the wrong method".
 *
 * WHAT A DRIVER MUST NOT DO
 *
 * It must not decide whether a user may sign in. An inactive account, a user with no roles, and
 * a user who must change their password are all application concerns that apply equally to every
 * driver, and duplicating them per driver is how the two drift apart. `authenticate()` answers
 * exactly one question: do these credentials identify this person?
 */
interface IdentityProvider
{
    /** The config value that selected this driver — 'local', 'entra'. For display and audit. */
    public function driver(): string;

    /**
     * Whether this driver can serve sign-in right now.
     *
     * A driver selected in config but missing its credentials is not a working driver, and the
     * distinction matters: without this, a half-configured SSO looks exactly like a wrong
     * password, and the administrator spends the afternoon resetting credentials that were
     * never the problem.
     */
    public function isConfigured(): bool;

    /**
     * What is missing, in words an administrator can act on. Null when nothing is.
     *
     * Names the setting and where to get it. "Entra is not configured" is a message that ends a
     * support call; "ENTRA_TENANT_ID is not set — find it in the Azure portal under App
     * registrations" is one that does not happen.
     */
    public function configurationProblem(): ?string;

    /** True when sign-in leaves this application and returns to it. */
    public function requiresRedirect(): bool;

    /**
     * Verify credentials held by this application.
     *
     * Returns null when they do not match. Must not distinguish "no such account" from "wrong
     * password" — the caller cannot un-distinguish them, and the difference is how an attacker
     * enumerates staff addresses.
     */
    public function authenticate(string $email, string $secret): ?User;

    /**
     * Where to send the browser to begin a redirect sign-in.
     *
     * @throws IdentityNotConfiguredException when called on an unconfigured driver
     */
    public function redirectUrl(): string;

    /**
     * Complete a redirect sign-in from the provider's callback parameters.
     *
     * @param  array<string, mixed>  $parameters
     *
     * @throws IdentityNotConfiguredException when called on an unconfigured driver
     */
    public function userFromCallback(array $parameters): ?User;

    /**
     * The profile this provider holds for a user (FR-002).
     *
     * Department, division and manager, as the identity provider knows them. `LocalProvider`
     * reads them from the user's own record, which is the best available answer when the
     * application is the only identity store. A real provider reads them from its claims — which
     * is why the return type is a value object rather than three properties read directly off
     * the User: the source differs, and the caller should not care.
     */
    public function profileFor(User $user): UserProfile;
}
