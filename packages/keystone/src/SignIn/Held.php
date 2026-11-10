<?php

namespace ClaudioDekker\Keystone\SignIn;

use ClaudioDekker\Keystone\Session\Stage;

/**
 * @internal
 */
readonly class Held
{
    /**
     * Create the outcome.
     */
    public function __construct(public Stage $stage)
    {
        //
    }
}
