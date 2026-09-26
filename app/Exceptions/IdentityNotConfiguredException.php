<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An identity driver was used before it was configured.
 *
 * WHY THIS IS AN EXCEPTION AND NOT A NULL RETURN
 *
 * Every method on `IdentityProvider` returns null for the ordinary "these credentials do not
 * match" case. If an unconfigured driver returned null too, the sign-in screen would say "those
 * details do not match our records" for a server-side misconfiguration — and the administrator
 * would reset the user's password, then the mailbox, then open a ticket with the identity
 * provider. All while the actual cause is one blank environment variable.
 *
 * Throwing separates "the user got it wrong" from "this is not set up", and it is caught at the
 * point where a readable message can be shown.
 */
class IdentityNotConfiguredException extends RuntimeException
{
    public static function forDriver(string $driver, string $problem): self
    {
        return new self("The '{$driver}' identity driver is not configured. {$problem}");
    }
}
