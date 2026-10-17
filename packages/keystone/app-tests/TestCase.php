<?php

namespace ClaudioDekker\Keystone\AppTests;

use ClaudioDekker\Keystone\AppTests\Concerns\AppliesBootstrapRules;
use ClaudioDekker\Keystone\AppTests\Concerns\InteractsWithKeystone;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Run an AppTest against the app itself, through its routes and published controllers.
 *
 * The app's tests/Keystone/TestCase.php extends this and uses its own assertion traits, which override Keystone's.
 *
 * @api
 */
abstract class TestCase extends BaseTestCase
{
    use AppliesBootstrapRules;
    use InteractsWithKeystone;
}
