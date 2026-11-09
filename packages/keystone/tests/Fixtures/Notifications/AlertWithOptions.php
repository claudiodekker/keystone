<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Notifications;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;

/**
 * @internal
 */
class AlertWithOptions extends Notification
{
    /**
     * Create the alert about the event, with options that keep their defaults.
     *
     * @param  array<string, string>  $options
     */
    public function __construct(public Model $event, public array $options = [])
    {
        //
    }
}
