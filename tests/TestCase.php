<?php

namespace Tests;

use ClaudioDekker\Keystone\Password\AppTests\Support\PasswordTypeSupport;
use ClaudioDekker\Keystone\Tests\Fixtures\FixturesServiceProvider;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use WithWorkbench;

    protected function getPackageProviders($app): array
    {
        return [FixturesServiceProvider::class, ...parent::getPackageProviders($app)];
    }

    protected function defineEnvironment($app): void
    {
        $app->bind('keystone.test-support.password', PasswordTypeSupport::class);
    }
}
