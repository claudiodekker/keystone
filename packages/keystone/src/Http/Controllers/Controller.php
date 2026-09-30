<?php

namespace ClaudioDekker\Keystone\Http\Controllers;

use ClaudioDekker\Keystone\Http\Middleware\ThrottleKeystoneRequests;
use ClaudioDekker\Keystone\StepKind;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * @api
 */
abstract class Controller implements HasMiddleware
{
    /**
     * Take the step kind's request limit before the given actions run.
     */
    protected static function throttle(StepKind $kind, string ...$actions): Middleware
    {
        return new Middleware(ThrottleKeystoneRequests::class.':'.$kind->value, only: $actions);
    }
}
