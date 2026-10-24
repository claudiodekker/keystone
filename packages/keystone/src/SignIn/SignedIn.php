<?php

namespace ClaudioDekker\Keystone\SignIn;

/**
 * @internal
 */
readonly class SignedIn
{
    /**
     * Create the outcome.
     */
    public function __construct(public string $redirectTo)
    {
        //
    }
}
