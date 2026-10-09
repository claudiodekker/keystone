<?php

namespace ClaudioDekker\Keystone\Exceptions;

use RuntimeException;

/**
 * @internal
 */
class AddressTaken extends RuntimeException
{
    /**
     * Create a new address taken exception instance.
     */
    public function __construct()
    {
        parent::__construct('An active account holds the address verified, or counts it as verified.');
    }
}
