<?php

namespace ClaudioDekker\Keystone\AppTests\Support;

/**
 * @api
 */
readonly class Browser
{
    /**
     * Create the browser with the session and the cookies it holds.
     *
     * @param  array<string, mixed>  $session
     * @param  array<string, string>  $cookies
     */
    public function __construct(
        public array $session,
        public array $cookies = [],
    ) {
        //
    }
}
