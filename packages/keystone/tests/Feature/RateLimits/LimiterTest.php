<?php

// The Retry-After arithmetic and the one programming error, driven without the sleeps a sign-in adds to the clock.

use ClaudioDekker\Keystone\Exceptions\MissingFlowException;
use ClaudioDekker\Keystone\Exceptions\ThrottledException;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\RateLimits\Limiter;
use ClaudioDekker\Keystone\RateLimits\StepKind;

it('asks to retry after the seconds left of the hour the failed attempts were counted in', function () {
    $type = Keystone::credentialTypes()->find('form', Surface::SIGN_IN);
    $limiter = $this->app->make(Limiter::class);

    foreach (range(1, config('keystone.rate_limits.failed_attempts_per_hour')) as $i) {
        $limiter->takeFailedAttempt($type, Surface::SIGN_IN, null, 'grace@example.com');
    }

    $this->travel(10)->minutes();
    $refused = fn () => $limiter->takeFailedAttempt($type, Surface::SIGN_IN, null, 'grace@example.com');

    expect($refused)->toThrow(fn (ThrottledException $exception) => expect($exception->getHeaders())->toBe(['Retry-After' => 50 * 60]));
});

it('asks to retry after the seconds left of the minute the requests were counted in', function () {
    $limiter = $this->app->make(Limiter::class);

    foreach (range(1, config('keystone.rate_limits.requests_per_minute.view')) as $i) {
        $limiter->takeRequest(StepKind::VIEW);
    }

    $this->travel(20)->seconds();
    $refused = fn () => $limiter->takeRequest(StepKind::VIEW);

    expect($refused)->toThrow(fn (ThrottledException $exception) => expect($exception->getHeaders())->toBe(['Retry-After' => 40]));
});

it('refuses to count a failed attempt on a surface that is in no flow', function () {
    $type = Keystone::credentialTypes()->find('form', Surface::SIGN_IN);
    $limiter = $this->app->make(Limiter::class);

    $take = fn () => $limiter->takeFailedAttempt($type, Surface::CHALLENGE, null, 'grace@example.com');

    expect($take)->toThrow(MissingFlowException::class, 'No flow counts failed attempts on the [challenge] surface.');
});
