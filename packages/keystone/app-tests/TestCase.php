<?php

namespace ClaudioDekker\Keystone\AppTests;

use ClaudioDekker\Keystone\AppTests\Concerns\AppliesBootstrapRules;
use ClaudioDekker\Keystone\AppTests\Concerns\InteractsWithKeystone;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * @api
 */
abstract class TestCase extends BaseTestCase
{
    use AppliesBootstrapRules;
    use InteractsWithKeystone;
}
