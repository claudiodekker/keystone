<?php

namespace ClaudioDekker\Keystone\AppTests\Support;

/**
 * @api
 */
readonly class Browser
{
    /**
     * Create the browser with the session it holds.
     *
     * @param  array<string, mixed>  $session
     */
    public function __construct(public array $session)
    {
        //
    }
}
