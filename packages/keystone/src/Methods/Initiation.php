<?php

namespace ClaudioDekker\Keystone\Methods;

/**
 * @internal
 */
readonly class Initiation
{
    /**
     * Create a new initiation instance.
     *
     * @param  mixed  $ceremony  what core keeps in the ceremony slot and hands back to verify, such as a new secret
     * @param  array<string, string>  $page
     */
    public function __construct(
        #[\SensitiveParameter] public mixed $ceremony = null,
        public array $page = [],
    ) {
        //
    }
}
