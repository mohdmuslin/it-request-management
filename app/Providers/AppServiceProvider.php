<?php

namespace App\Providers;

use App\Contracts\IdentityProvider;
use App\Identity\EntraProvider;
use App\Identity\LocalProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * THE IDENTITY BINDING IS THE WHOLE POINT OF THE INTERFACE.
         *
         * FR-001 requires enterprise identity and SSO. The decision on which provider has not
         * been made yet, so the requirement is met here by making the answer a CONFIGURATION
         * value rather than a code change: set `ITREQUEST_IDENTITY_DRIVER` and this resolves a
         * different implementation.
         *
         * Bound as a singleton as well as an alias, because `IdentityProvider` is resolved in
         * several places per request — the sign-in screen, the controller that starts a redirect,
         * and the callback. A new instance each time would mean re-reading config, and for the
         * Entra driver re-fetching the signing keys if the cache were cold.
         *
         * `IdentityManager` is what callers should use: it can report WHY a driver is unusable,
         * which a bare binding cannot. The binding exists so that a class needing *a* provider
         * can be injected without knowing which one, which is the seam this requirement asks for.
         */
        $this->app->singleton(IdentityProvider::class, function () {
            return match (config('itrequest.identity.driver', 'local')) {
                'entra' => new EntraProvider,
                default => new LocalProvider,
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
