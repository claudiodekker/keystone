<?php

namespace ClaudioDekker\Keystone\Tests;

use ClaudioDekker\Keystone\AppTests\Concerns\AppliesBootstrapRules;
use ClaudioDekker\Keystone\Tests\Fixtures\FixtureServiceProvider;
use Composer\InstalledVersions;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Orchestra\Testbench\TestCase as BaseTestCase;

/**
 * Run a test in an app with every installed package, held to the suite's rules.
 */
class TestCase extends BaseTestCase
{
    use AppliesBootstrapRules;
    use LazilyRefreshDatabase;

    /**
     * Set the app up, rendering pages without the workbench's built assets, and send what a browser sends from the app's own pages.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->withHeader('Sec-Fetch-Site', 'same-origin');
    }

    /**
     * Serialize the session as Laravel's skeleton does, which Testbench's config leaves out.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('session.serialization', 'json');
    }

    /**
     * Get the fixture app's provider, then the providers of every package Composer installed.
     *
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        $files = new Filesystem;

        return collect($files->glob(dirname(__DIR__).'/packages/*/composer.json'))
            ->map(fn (string $path) => $files->json($path))
            ->filter(fn (array $manifest) => InstalledVersions::isInstalled($manifest['name']))
            ->flatMap(fn (array $manifest) => $manifest['extra']['laravel']['providers'] ?? [])
            ->prepend(FixtureServiceProvider::class)
            ->values()
            ->all();
    }
}
