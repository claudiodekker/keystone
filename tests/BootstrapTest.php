<?php

use ClaudioDekker\Keystone\Tests\Fixtures\RecordsItsRun;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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
