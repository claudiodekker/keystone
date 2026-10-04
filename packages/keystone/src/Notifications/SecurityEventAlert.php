<?php

namespace ClaudioDekker\Keystone\Notifications;

use ClaudioDekker\Keystone\SecurityEvent;

/**
 * @api
 */
interface SecurityEventAlert
{
    /**
     * Create a new alert about the security event.
     */
    public function __construct(SecurityEvent $event);
}
