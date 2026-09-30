<?php

namespace ClaudioDekker\Keystone;

use RuntimeException;

/**
 * @api
 */
class AlreadySuspended extends RuntimeException
{
    /**
     * Create a new already suspended exception instance.
     */
    public function __construct()
    {
        parent::__construct('The account is already suspended.');
    }
}
