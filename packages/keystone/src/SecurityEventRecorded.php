<?php

namespace ClaudioDekker\Keystone;

/**
 * @api
 */
readonly class SecurityEventRecorded
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public SecurityEvent $event,
    ) {
        //
    }
}
