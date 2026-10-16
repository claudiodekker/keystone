<?php

use ClaudioDekker\Keystone\Tests\Fixtures\RecordsItsRun;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

it('fails an http call nothing faked', function () {
    expect(fn () => Http::get('https://keystone.test'))->toThrow(StrayRequestException::class);
});

it('fails a push to a real queue', function () {
    expect(fn () => Queue::connection('database')->push(new RecordsItsRun))
        ->toThrow(LogicException::class, 'to the [database] queue');
});

it('runs a job pushed to sync', function () {
    Queue::connection('sync')->push(new RecordsItsRun);

    expect(Cache::get('runs'))->toBe(1);
});

it('holds the clock still while real time passes', function () {
    $before = now();

    usleep(2000);

    expect(now()->equalTo($before))->toBeTrue();
});

it('moves the clock by exactly the length of a sleep, without waiting', function () {
    $before = now();
    $started = hrtime(true);

    Sleep::for(2)->seconds();

    expect(hrtime(true) - $started)->toBeLessThan(1_000_000_000)
        ->and(now()->equalTo($before->addSeconds(2)))->toBeTrue();
});

it('passes when the test asserts the report', function () {
    report(new RuntimeException('Alert failed to send.'));

    Exceptions::assertReported(RuntimeException::class);
});

it('fails a report the test does not assert', function () {
    $result = Process::path(dirname(__DIR__))
        ->run([PHP_BINARY, 'vendor/bin/pest', 'tests/Fixtures/reports-without-asserting.php']);

    expect($result->exitCode())->toBe(1)
        ->and($result->output())->toContain('Reported [RuntimeException] without asserting it');
});

it('names the fix when a test fakes the exception handler itself', function () {
    $result = Process::path(dirname(__DIR__))
        ->run([PHP_BINARY, 'vendor/bin/pest', 'tests/Fixtures/fakes-the-exception-handler.php']);

    expect($result->exitCode())->toBe(1)
        ->and($result->output())->toContain('The suite already fakes the exception handler. Remove the Exceptions::fake() call from the test.');
});
