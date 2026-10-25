<?php

namespace ClaudioDekker\Keystone\Events;

use ClaudioDekker\Keystone\Models\UserSecurityEvent;

/**
 * @api
 */
class SecurityEventRecorded
{
    /**
     * Create the event.
     *
     * The entry may be unsaved, when its row could not be written, so a listener reads it rather than reloading it.
     */
    public function __construct(public UserSecurityEvent $entry)
    {
        //
    }
}
