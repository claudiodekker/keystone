<?php

namespace ClaudioDekker\Keystone;

/**
 * @internal
 */
readonly class RunningCeremony
{
    /**
     * Create a new running ceremony instance.
     *
     * @param  mixed  $ceremony  what the type's initiate made and its verify gets back, such as a new secret
     * @param  array<string, string>  $page  what the type's enrollment form shows
     */
    public function __construct(
        #[\SensitiveParameter] public mixed $ceremony,
        public array $page,
    ) {
        //
    }
}
