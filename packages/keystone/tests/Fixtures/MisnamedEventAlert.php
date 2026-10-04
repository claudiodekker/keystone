<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures;

use ClaudioDekker\Keystone\SecurityEvent;
use Illuminate\Notifications\Notification;

class MisnamedEventAlert extends Notification
{
    /**
     * Create a new misnamed event alert instance.
     */
    public function __construct(
        public SecurityEvent $securityEvent,
    ) {
        //
    }
}
