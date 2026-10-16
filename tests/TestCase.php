<?php

namespace ClaudioDekker\Keystone\Tests;

use ClaudioDekker\Keystone\AppTests\Concerns\AppliesTheBootstrapRules;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Orchestra\Testbench\TestCase as BaseTestCase;

/**
 * Run a test in an app with every package installed, held to the suite's rules.
 */
class TestCase extends BaseTestCase
{
    use AppliesTheBootstrapRules;
    use LazilyRefreshDatabase;

    /**
     * Get the service providers every package registers through package discovery.
     *
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        $files = new Filesystem;

        return collect($files->glob(dirname(__DIR__).'/packages/*/composer.json'))
            ->flatMap(fn (string $path) => $files->json($path)['extra']['laravel']['providers'] ?? [])
            ->values()
            ->all();
    }
}
