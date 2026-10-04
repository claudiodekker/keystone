<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;

class LooseEventAlert extends Notification
{
    /**
     * Create a new loose event alert instance.
     */
    public function __construct(
        public Model $event,
    ) {
        //
    }
}
