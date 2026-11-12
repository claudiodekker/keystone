<?php

namespace ClaudioDekker\Keystone\SignIn;

/**
 * @internal
 */
readonly class NoSudoInProgress
{
    /**
     * Create the outcome.
     */
    public function __construct(public string $redirectTo)
    {
        //
    }
}
