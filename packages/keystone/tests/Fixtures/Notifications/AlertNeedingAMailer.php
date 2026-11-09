<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Notifications;

use ClaudioDekker\Keystone\Models\UserSecurityEvent;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Notifications\Notification;

/**
 * @internal
 */
class AlertNeedingAMailer extends Notification
{
    /**
     * Create the alert about the event, with a mailer it asks the container for.
     */
    public function __construct(public UserSecurityEvent $event, public Mailer $mailer)
    {
        //
    }
}
