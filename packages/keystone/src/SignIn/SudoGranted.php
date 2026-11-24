<?php

namespace ClaudioDekker\Keystone\SignIn;

/**
 * @internal
 */
readonly class SudoGranted
{
    /**
     * Create the outcome.
     */
    public function __construct(public string $redirectTo)
    {
        //
    }
}
