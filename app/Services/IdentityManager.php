<?php

namespace App\Services;

use App\Contracts\IdentityProvider;
use App\Exceptions\IdentityNotConfiguredException;
use App\Identity\EntraProvider;
use App\Identity\LocalProvider;
use Illuminate\Support\Facades\Log;

/**
 * The configured identity provider, resolved once per request.
 *
 * WHY THIS IS A CLASS AND NOT `app(IdentityProvider::class)`
 *
 * The binding alone would be enough if the configured driver were always usable. It is not: a
 * driver can be selected and not configured, which is the normal state while an app registration
 * is pending. Resolving that to an exception *at sign-in* means the user sees a broken form
 * rather than a reason, and the administrator sees nothing.
 *
 * So this exists to answer one question the binding cannot: **is the configured provider usable,
 * and if not, why not** — in time for the sign-in screen to say so.
 *
 * THE FALLBACK IS DELIBERATELY ABSENT
 *
 * If Entra is selected and not configured, this does NOT quietly fall back to local. A silent
 * fallback would mean the application signs people in by password while the configuration says
 * it uses SSO — so the setting appears to work, nobody investigates why the app registration is
 * still pending, and the passwords everybody was told are unused remain live.
 *
 * It reports the problem instead. `fallback()` is available for the one caller that needs it,
 * and it is explicit and logged when used.
 */
class IdentityManager
{
    /** Cache per request; config does not change mid-request. */
    private ?IdentityProvider $resolved = null;

    /** @var array<string, class-string<IdentityProvider>> */
    private const DRIVERS = [
        'local' => LocalProvider::class,
        'entra' => EntraProvider::class,
    ];

    public function driverName(): string
    {
        return (string) config('itrequest.identity.driver', 'local');
    }

    /**
     * The configured provider.
     *
     * @throws IdentityNotConfiguredException when the driver is unknown or unusable
     */
    public function provider(): IdentityProvider
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $name = $this->driverName();
        $class = self::DRIVERS[$name] ?? null;

        if ($class === null) {
            throw IdentityNotConfiguredException::forDriver(
                $name,
                'Known drivers are: '.implode(', ', array_keys(self::DRIVERS))
                    .'. Set ITREQUEST_IDENTITY_DRIVER to one of those.'
            );
        }

        /** @var IdentityProvider $provider */
        $provider = app($class);

        if (! $provider->isConfigured()) {
            throw IdentityNotConfiguredException::forDriver($name, (string) $provider->configurationProblem());
        }

        return $this->resolved = $provider;
    }

    /**
     * The configured provider if it is usable, otherwise local.
     *
     * FOR SIGN-IN ONLY, AND IT LOGS EVERY TIME IT DIVERGES.
     *
     * This is what stops a pending SSO decision locking every user out of the application: with
     * Entra selected and unconfigured, sign-in still works locally while the misconfiguration is
     * recorded. The alternative — refusing to serve any login form — turns a configuration
     * mistake into an outage.
     */
    public function fallback(): IdentityProvider
    {
        try {
            return $this->provider();
        } catch (IdentityNotConfiguredException $e) {
            Log::warning(
                'Identity driver unusable; sign-in fell back to local accounts. '
                .'This should be temporary — either finish the configuration or set '
                .'ITREQUEST_IDENTITY_DRIVER=local.',
                ['driver' => $this->driverName(), 'problem' => $e->getMessage()]
            );

            return app(LocalProvider::class);
        }
    }

    /**
     * Whether sign-in can proceed, and what to tell the user if not.
     *
     * @return array{ready: bool, driver: string, problem: string|null}
     */
    public function status(): array
    {
        try {
            $provider = $this->provider();

            return ['ready' => true, 'driver' => $provider->driver(), 'problem' => null];
        } catch (IdentityNotConfiguredException $e) {
            return ['ready' => false, 'driver' => $this->driverName(), 'problem' => $e->getMessage()];
        }
    }

    /** Forget the per-request cache. For tests that change the driver between assertions. */
    public function forget(): void
    {
        $this->resolved = null;
    }
}
