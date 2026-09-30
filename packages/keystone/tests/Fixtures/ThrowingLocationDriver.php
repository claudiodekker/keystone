<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures;

use Illuminate\Support\Fluent;
use RuntimeException;
use Stevebauman\Location\Drivers\Driver;
use Stevebauman\Location\Position;
use Stevebauman\Location\Request;

class ThrowingLocationDriver extends Driver
{
    protected function process(Request $request): Fluent|false
    {
        throw new RuntimeException('Driver broke.');
    }

    protected function hydrate(Position $position, Fluent $location): Position
    {
        return $position;
    }
}
