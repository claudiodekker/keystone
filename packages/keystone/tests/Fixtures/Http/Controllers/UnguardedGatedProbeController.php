<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers;

class UnguardedGatedProbeController extends GatedProbeController
{
    public static function middleware(): array
    {
        return [];
    }
}
