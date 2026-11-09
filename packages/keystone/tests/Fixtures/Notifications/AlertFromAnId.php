<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Notifications;

use Illuminate\Notifications\Notification;

/**
 * @internal
 */
class AlertFromAnId extends Notification
{
    /**
     * Create the alert about the event with the id.
     */
    public function __construct(public int $eventId)
    {
        //
    }
}
