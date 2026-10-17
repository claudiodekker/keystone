<?php

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\HttpFoundation\Cookie;

it('passes two responses that differ only in their body and Date header', function () {
    $existing = TestResponse::fromBaseResponse(new Response('one', 302, ['Location' => '/login', 'Date' => 'Mon, 05 Oct 2026 10:00:00 GMT']));
    $missing = TestResponse::fromBaseResponse(new Response('two', 302, ['Location' => '/login', 'Date' => 'Mon, 05 Oct 2026 10:00:01 GMT']));

    $this->assertIndistinguishable($existing, $missing);
});

it('fails on a different status', function () {
    $existing = TestResponse::fromBaseResponse(new Response('', 302));
    $missing = TestResponse::fromBaseResponse(new Response('', 303));

    expect(fn () => $this->assertIndistinguishable($existing, $missing))->toThrow(AssertionFailedError::class, 'The responses differ in status.');
});

it('fails on a different redirect', function () {
    $existing = TestResponse::fromBaseResponse(new RedirectResponse('/login'));
    $missing = TestResponse::fromBaseResponse(new RedirectResponse('/register'));

    expect(fn () => $this->assertIndistinguishable($existing, $missing))->toThrow(AssertionFailedError::class, 'The responses differ in redirect.');
});

it('fails on a different header name', function () {
    $existing = TestResponse::fromBaseResponse(new Response('', 302, ['X-Account' => 'known']));
    $missing = TestResponse::fromBaseResponse(new Response('', 302));

    expect(fn () => $this->assertIndistinguishable($existing, $missing))->toThrow(AssertionFailedError::class, 'The responses differ in header names.');
});

it('fails on a different Set-Cookie name', function () {
    $existingBase = new Response;
    $existingBase->headers->setCookie(Cookie::create('app_session', 'one'));
    $existingBase->headers->setCookie(Cookie::create('known_device', 'yes'));
    $missingBase = new Response;
    $missingBase->headers->setCookie(Cookie::create('app_session', 'two'));
    $existing = TestResponse::fromBaseResponse($existingBase);
    $missing = TestResponse::fromBaseResponse($missingBase);

    expect(fn () => $this->assertIndistinguishable($existing, $missing))->toThrow(AssertionFailedError::class, 'The responses differ in Set-Cookie names.');
});
