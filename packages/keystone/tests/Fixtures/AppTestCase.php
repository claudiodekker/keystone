<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures;

use ClaudioDekker\Keystone\AppTests\Concerns\InteractsWithKeystone;
use ClaudioDekker\Keystone\Tests\TestCase;

/**
 * Run the AppTests in Testbench against the fixture app, as core CI does.
 */
class AppTestCase extends TestCase
{
    use InteractsWithKeystone;
}
