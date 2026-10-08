<?php

namespace ClaudioDekker\Keystone\Exceptions;

use RuntimeException;

/**
 * @internal
 */
class LastSecondFactor extends RuntimeException
{
    /**
     * Create a new last second factor exception instance.
     */
    public function __construct()
    {
        parent::__construct('Your last second factor is kept while the app requires one.');
    }
}
