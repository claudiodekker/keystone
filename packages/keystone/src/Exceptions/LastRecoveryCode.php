<?php

namespace ClaudioDekker\Keystone\Exceptions;

use RuntimeException;

/**
 * @internal
 */
class LastRecoveryCode extends RuntimeException
{
    /**
     * Create a new last recovery code exception instance.
     */
    public function __construct()
    {
        parent::__construct('The last recovery code is kept for account recovery.');
    }
}
