<?php

namespace ClaudioDekker\Keystone\AppTests\Concerns;

use ClaudioDekker\Keystone\AppTests\ExceptionHandlerFake;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use LogicException;
use PHPUnit\Framework\Assert;

/**
 * Hold a test to the suite's rules: no stray HTTP, no real queue push, a frozen clock and asserted reports.
 *
 * @api
 */
trait AppliesTheBootstrapRules
{
    /**
     * Apply the rules once the app is up.
     */
    protected function setUpAppliesTheBootstrapRules(): void
    {
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
            throw new LogicException("Pushed [{$payload['displayName']}] to the [{$connection}] queue. Fake the queue in the test with Queue::fake().");
        }

        return [];
    }
}
