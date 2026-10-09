<?php

namespace ClaudioDekker\Keystone\Http\Controllers;

use ClaudioDekker\Keystone\Http\Middleware\RequireOpenRegistration;
use ClaudioDekker\Keystone\Http\Middleware\RequireSudo;
use ClaudioDekker\Keystone\Http\Middleware\SendNoReferrer;
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

    /**
     * Send no referrer from the given actions, whose URLs carry an emailed link, whatever answers them.
     */
    protected static function noReferrer(string ...$actions): Middleware
    {
        return new Middleware(SendNoReferrer::class, only: $actions);
    }

    /**
     * Require registration to be open before every action runs, whatever an app's override of them does.
     */
    protected static function openRegistration(): Middleware
    {
        return new Middleware(RequireOpenRegistration::class);
    }

    /**
     * Require sudo before the given actions run, whatever an app's override of them does.
     */
    protected static function sudo(string ...$actions): Middleware
    {
        return new Middleware(RequireSudo::class, only: $actions);
    }
}
