<?php

namespace ClaudioDekker\Keystone;

use RuntimeException;

/**
 * @api
 */
class NotSuspended extends RuntimeException
{
    /**
     * Create a new not suspended exception instance.
     */
    public function __construct()
    {
        parent::__construct('The account is not suspended.');
    }
}
