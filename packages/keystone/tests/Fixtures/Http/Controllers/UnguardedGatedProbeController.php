<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers;

/**
 * An app's subclass that replaced the controller's middleware list, and with it the sudo middleware.
 */
class UnguardedGatedProbeController extends GatedProbeController
{
    public static function middleware(): array
    {
        return [];
    }
}
