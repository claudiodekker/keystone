<?php

namespace Tests;

use ClaudioDekker\Keystone\Tests\Fixtures\FixturesServiceProvider;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use WithWorkbench;

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), FixturesServiceProvider::class];
    }
}
