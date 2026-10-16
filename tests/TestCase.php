<?php

namespace ClaudioDekker\Keystone\Tests;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use LogicException;
use Orchestra\Testbench\TestCase as BaseTestCase;
use PHPUnit\Framework\Assert;

/**
 * Run a test in an app with every package installed, held to the suite's rules.
 */
class TestCase extends BaseTestCase
{
    use LazilyRefreshDatabase;

    /**
     * Set up the app and hold the test to the suite's rules.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Queue::createPayloadUsing($this->refuseRealQueuePushes(...));
        $this->freezeTime();
        Sleep::fake(syncWithCarbon: true);
        Exceptions::swap(new ExceptionHandlerFake($this->app->make(ExceptionHandler::class)));

        $this->beforeApplicationDestroyed($this->assertEveryReportAsserted(...));
    }

    /**
     * Assert the test asserted every exception it reported.
     */
    protected function assertEveryReportAsserted(): void
    {
        $handler = Exceptions::getFacadeRoot();

        if (! $handler instanceof ExceptionHandlerFake) {
            Assert::fail('The suite already fakes the exception handler. Remove the Exceptions::fake() call from the test.');
        }

        $handler->assertEveryReportAsserted();
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
