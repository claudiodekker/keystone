<?php

namespace ClaudioDekker\Keystone\AppTests\Support;

/**
 * The cookies a browser held at one moment, to come back with later.
 *
 * @api
 */
readonly class Browser
{
    /**
     * Create the browser.
     *
     * @param  array<string, string>  $cookies
     */
    public function __construct(public array $cookies)
    {
        //
    }
}
