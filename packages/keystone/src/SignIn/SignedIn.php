<?php

namespace ClaudioDekker\Keystone\SignIn;

/**
 * A completed sign-in, and where to send the user.
 *
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
