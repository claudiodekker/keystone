<?php

use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\AssertionFailedError;

it('fails assertHardened on a response without the floor', function () {
    $response = TestResponse::fromBaseResponse(new Response);

    expect(fn () => $this->assertHardened($response))->toThrow(AssertionFailedError::class, 'no-store');
});

it('fails assertHardened on a cacheable response', function () {
    $response = $this->get(route('login'));
    $response->headers->set('Cache-Control', 'public, max-age=3600');

    expect(fn () => $this->assertHardened($response))->toThrow(AssertionFailedError::class, 'no-store');
});

it('fails assertHardened on a response another site may frame', function () {
    $response = $this->get(route('login'));
    $response->headers->remove('X-Frame-Options');

    expect(fn () => $this->assertHardened($response))->toThrow(AssertionFailedError::class, 'X-Frame-Options');
});

it('fails assertHardened on a policy that lets objects load', function () {
    $response = $this->get(route('login'));
    $response->headers->set('Content-Security-Policy', "object-src 'self'; base-uri 'none'; frame-ancestors 'none'");

    expect(fn () => $this->assertHardened($response))->toThrow(AssertionFailedError::class, '[object-src]');
});

it('fails assertHardened on a second policy that leaves the base open', function () {
    $response = $this->get(route('login'));
    $response->headers->set('Content-Security-Policy', [
        "object-src 'none'; base-uri 'none'; frame-ancestors 'none'",
        "object-src 'none'; frame-ancestors 'none'",
    ]);

    expect(fn () => $this->assertHardened($response))->toThrow(AssertionFailedError::class, '[base-uri]');
});

it('fails assertHardened on a response with no policy', function () {
    $response = $this->get(route('login'));
    $response->headers->remove('Content-Security-Policy');

    expect(fn () => $this->assertHardened($response))->toThrow(AssertionFailedError::class, 'no Content-Security-Policy');
});

it('fails assertHardened on a response with a loosened header', function (string $header, string $value) {
    $response = $this->get(route('login'));
    $response->headers->set($header, $value);

    expect(fn () => $this->assertHardened($response))->toThrow(AssertionFailedError::class);
})->with([
    'Pragma' => ['Pragma', 'cache'],
    'X-Content-Type-Options' => ['X-Content-Type-Options', 'sniff'],
    'Referrer-Policy' => ['Referrer-Policy', 'unsafe-url'],
    'Cross-Origin-Opener-Policy' => ['Cross-Origin-Opener-Policy', 'unsafe-none'],
    'Cross-Origin-Resource-Policy' => ['Cross-Origin-Resource-Policy', 'cross-origin'],
    'X-Frame-Options' => ['X-Frame-Options', 'SAMEORIGIN'],
]);
