<?php

namespace ClaudioDekker\Keystone\Exceptions;

use RuntimeException;

/**
 * @internal
 */
class CurrentSession extends RuntimeException
{
    /**
     * Create a new current session exception instance.
     */
    public function __construct()
    {
        parent::__construct('The session making the request is signed out, not revoked.');
    }
}
