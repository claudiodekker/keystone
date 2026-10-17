<?php

use ClaudioDekker\Keystone\Exceptions\ThrottledException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('refuses with 429, Retry-After in seconds and the throttled message', function () {
    $exception = ThrottledException::retryAfter(37);

    expect($exception->getStatusCode())->toBe(429)
        ->and($exception->getHeaders())->toBe(['Retry-After' => 37])
        ->and($exception->getMessage())->toBe('Too many attempts. Please try again in 37 seconds.');
});

it('asks to retry after at least one second', function (int $seconds) {
    $exception = ThrottledException::retryAfter($seconds);

    expect($exception->getHeaders())->toBe(['Retry-After' => 1]);
})->with([
    'no seconds' => 0,
    'a negative number of seconds' => -5,
]);

it('is never reported, even when the app stops ignoring HTTP exceptions', function () {
    $handler = $this->app->make(ExceptionHandler::class);
    $handler->stopIgnoring(HttpException::class);

    expect($handler->shouldReport(ThrottledException::retryAfter(1)))->toBeFalse();
});
