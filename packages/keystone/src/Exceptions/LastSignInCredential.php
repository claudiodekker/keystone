<?php

namespace ClaudioDekker\Keystone\Exceptions;

use RuntimeException;

/**
 * @internal
 */
class LastSignInCredential extends RuntimeException
{
    /**
     * Create a new last sign-in credential exception instance.
     */
    public function __construct()
    {
        parent::__construct('Your only way to sign in is kept.');
    }
}
