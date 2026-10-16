<?php

namespace ClaudioDekker\Keystone\Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Orchestra\Testbench\TestCase as BaseTestCase;

/**
 * Run a test in an app with every package installed, held to the suite's rules.
 */
class TestCase extends BaseTestCase
{
    /**
     * Set up the app and hold the test to the suite's rules.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Queue::createPayloadUsing($this->refuseRealQueuePushes(...));
    }

    /**
     * Refuse a job pushed to any queue connection but sync.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function refuseRealQueuePushes(string $connection, ?string $queue, array $payload): array
    {
        if ($connection !== 'sync') {
            throw new LogicException("Pushed [{$payload['displayName']}] to the [{$connection}] queue. Fake it with Queue::fake(), or dispatch it on sync.");
        }

        return [];
    }

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
