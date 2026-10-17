<?php

namespace ClaudioDekker\Keystone\Exceptions;

use LogicException;

/**
 * Refuse a sign-in through a SessionGuard method that has no way to report a refusal.
 *
 * @internal
 *
 * @phpstan-consistent-constructor
 */
class SessionGuardSignInException extends LogicException
{
    /**
     * Create the exception for Auth::login().
     */
    public static function login(): static
    {
        return new static('Keystone signs users in. Auth::login() cannot report a refusal.');
    }
}
