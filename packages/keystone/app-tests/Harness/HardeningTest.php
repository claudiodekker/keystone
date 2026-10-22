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

it('fails assertFramableBy on a response that sets X-Frame-Options', function () {
    $response = $this->get(route('login'));

    expect(fn () => $this->assertFramableBy($response, ['https://portal.example.com']))->toThrow(AssertionFailedError::class, 'X-Frame-Options');
});

it('fails assertFramableBy on a response other sources may frame', function () {
    config(['keystone.hardening.frame_ancestors' => ['https://portal.example.com', 'https://other.example']]);

    $response = $this->get(route('login'));

    expect(fn () => $this->assertFramableBy($response, ['https://portal.example.com']))->toThrow(AssertionFailedError::class, 'other sources');
});

it('fails assertContentSecurityPolicies on a policy that lacks a directive', function () {
    $response = $this->get(route('login'));

    expect(fn () => $this->assertContentSecurityPolicies($response, [
        ['base-uri' => "'none'", 'frame-ancestors' => "'none'"],
    ]))->toThrow(AssertionFailedError::class);
});

it('fails assertContentSecurityPolicies on one policy fewer than the response carries', function () {
    $this->appSetsHeaders(['Content-Security-Policy' => ["default-src 'self'", "img-src 'self'"]]);

    $response = $this->get(route('login'));

    expect(fn () => $this->assertContentSecurityPolicies($response, [
        ['base-uri' => "'none'", 'default-src' => "'self'", 'frame-ancestors' => "'none'", 'object-src' => "'none'"],
    ]))->toThrow(AssertionFailedError::class);
});

it('passes assertContentSecurityPolicies on the same policies in another order', function () {
    $this->appSetsHeaders(['Content-Security-Policy' => ["default-src 'self'", "img-src 'self'"]]);

    $response = $this->get(route('login'));

    $this->assertContentSecurityPolicies($response, [
        ['object-src' => "'none'", 'img-src' => "'self'", 'frame-ancestors' => "'none'", 'base-uri' => "'none'"],
        ['frame-ancestors' => "'none'", 'base-uri' => "'none'", 'default-src' => "'self'", 'object-src' => "'none'"],
    ]);
});

it('fails assertSiteDataCleared on a response that clears no site data', function () {
    $response = $this->post(route('login.submit', 'unknown'));

    expect(fn () => $this->assertSiteDataCleared($response, ['cache']))->toThrow(AssertionFailedError::class, 'does not clear any site data');
});

it('fails assertSiteDataCleared on a response that clears another kind', function () {
    $response = $this->post(route('logout'));

    expect(fn () => $this->assertSiteDataCleared($response, ['cache', 'cookies']))->toThrow(AssertionFailedError::class, 'other site data');
});

it('fails assertSiteDataCleared on a response that clears more kinds', function () {
    $response = $this->post(route('logout'));

    expect(fn () => $this->assertSiteDataCleared($response, ['cache']))->toThrow(AssertionFailedError::class, 'other site data');
});

it('fails assertSiteDataCleared on a header that is not a list of quoted kinds', function () {
    $response = $this->post(route('logout'));
    $response->headers->set('Clear-Site-Data', 'cache, storage');

    expect(fn () => $this->assertSiteDataCleared($response, ['cache', 'storage']))->toThrow(AssertionFailedError::class, 'quoted kinds');
});

it('fails assertSiteDataKept on a response that clears site data', function () {
    $response = $this->post(route('logout'));

    expect(fn () => $this->assertSiteDataKept($response))->toThrow(AssertionFailedError::class, 'clears site data');
});
