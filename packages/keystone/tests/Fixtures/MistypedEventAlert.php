<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures;

use Illuminate\Notifications\Notification;

class MistypedEventAlert extends Notification
{
    /**
     * Create a new mistyped event alert instance.
     */
    public function __construct(
        public string $event,
    ) {
        //
    }
}
