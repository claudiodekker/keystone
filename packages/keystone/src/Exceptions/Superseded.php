<?php

namespace ClaudioDekker\Keystone\Exceptions;

use RuntimeException;

/**
 * @internal
 */
class Superseded extends RuntimeException
{
    /**
     * Create a new superseded exception instance.
     */
    public function __construct()
    {
        parent::__construct('The credentials the answer was checked against changed meanwhile.');
    }
}
