<?php

namespace ClaudioDekker\Keystone\Notifications\Contracts;

use ClaudioDekker\Keystone\SecurityEvent;

/**
 * @api
 */
interface SecurityEventAlertContract
{
    /**
     * Create a new alert about the security event, and about the others of its type that one alert covers.
     */
    public function __construct(SecurityEvent $event, SecurityEvent ...$others);
}
