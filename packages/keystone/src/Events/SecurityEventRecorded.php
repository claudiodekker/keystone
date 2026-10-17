<?php

namespace ClaudioDekker\Keystone\Events;

use ClaudioDekker\Keystone\Models\UserSecurityEvent;

/**
 * A security event was recorded.
 *
 * The entry may be unsaved, when its row could not be written, so a listener reads it rather than reloading it.
 *
 * @api
 */
class SecurityEventRecorded
{
    /**
     * Create the event.
     */
    public function __construct(public UserSecurityEvent $entry)
    {
        //
    }
}
