<?php

namespace ClaudioDekker\Keystone\AppTests\Support;

use ClaudioDekker\Keystone\Models\UserSecurityEvent;
use Illuminate\Notifications\Notification;

/**
 * @internal
 */
class AppAlert extends Notification
{
    /**
     * Create the alert an app sends in place of Keystone's, about the event.
     */
    public function __construct(public UserSecurityEvent $event)
    {
        //
    }

    /**
     * Get the channels the alert is sent on.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }
}
