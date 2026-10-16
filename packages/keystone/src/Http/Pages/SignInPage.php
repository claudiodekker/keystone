<?php

namespace ClaudioDekker\Keystone\Http\Pages;

/**
 * What the sign-in page shows; both adapters pass these fields on under these names.
 *
 * @api
 */
readonly class SignInPage
{
    /**
     * Create the page value.
     *
     * @param  list<array{name: string, shape: string}>  $types
     */
    public function __construct(public array $types)
    {
        //
    }
}
