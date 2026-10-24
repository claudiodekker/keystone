<?php

namespace ClaudioDekker\Keystone\Exceptions;

use LogicException;

/**
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
