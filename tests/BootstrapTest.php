<?php

use ClaudioDekker\Keystone\Tests\Fixtures\RecordsItsRun;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

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
