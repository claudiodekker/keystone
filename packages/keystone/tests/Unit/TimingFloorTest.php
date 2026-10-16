<?php

use ClaudioDekker\Keystone\SignIn\TimingFloor;
use Illuminate\Support\Carbon;

it('waits out the rest of 300 ms after work that took less', function () {
    $startedAt = now();

    (new TimingFloor)->hold(fn () => Carbon::setTestNow(now()->addMilliseconds(120)), fn () => false);

    expect($startedAt->diffInMilliseconds(now()))->toBe(300.0);
});

it('waits nothing after work that took longer than the floor', function () {
    $startedAt = now();

    (new TimingFloor)->hold(fn () => Carbon::setTestNow(now()->addMilliseconds(450)), fn () => false);

    expect($startedAt->diffInMilliseconds(now()))->toBe(450.0);
});

it('answers at once when the result may answer early', function () {
    $startedAt = now();

    (new TimingFloor)->hold(fn () => Carbon::setTestNow(now()->addMilliseconds(120)), fn () => true);

    expect($startedAt->diffInMilliseconds(now()))->toBe(120.0);
});

it('waits out the floor before the exception of failed work goes on', function () {
    $startedAt = now();

    $hold = fn () => (new TimingFloor)->hold(fn () => throw new RuntimeException('The type failed to verify.'), fn () => false);

    expect($hold)->toThrow(RuntimeException::class, 'The type failed to verify.')
        ->and($startedAt->diffInMilliseconds(now()))->toBe(300.0);
});
