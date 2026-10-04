<?php

namespace ClaudioDekker\Keystone\Notifications\Contracts;

use ClaudioDekker\Keystone\SecurityEvent;

/**
 * @api
 */
interface SecurityEventAlertContract
{
    /**
     * Create a new alert about the security event.
     */
    public function __construct(SecurityEvent $event);
}
