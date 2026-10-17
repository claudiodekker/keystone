<?php

use ClaudioDekker\Keystone\AppTests\KeystoneTestResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\HttpFoundation\Cookie;

it('passes two responses that differ only in their body and Date header', function () {
    $session = new Store('session', new ArraySessionHandler(1));
    $existing = KeystoneTestResponse::capture(TestResponse::fromBaseResponse(new Response('one', 302, ['Location' => '/login', 'Date' => 'Mon, 05 Oct 2026 10:00:00 GMT'])), $session, 300);
    $missing = KeystoneTestResponse::capture(TestResponse::fromBaseResponse(new Response('two', 302, ['Location' => '/login', 'Date' => 'Mon, 05 Oct 2026 10:00:01 GMT'])), $session, 300);

    $this->assertIndistinguishable($existing, $missing);
});

it('passes flashed-back input that differs only in what was typed', function () {
    $existingSession = new Store('session', new ArraySessionHandler(1));
    $existingSession->flashInput(['identifier' => 'ada@example.com']);
    $existingSession->ageFlashData();
    $missingSession = new Store('session', new ArraySessionHandler(1));
    $missingSession->flashInput(['identifier' => 'grace@example.com']);
    $missingSession->ageFlashData();
    $existing = KeystoneTestResponse::capture(TestResponse::fromBaseResponse(new Response), $existingSession, 300);
    $missing = KeystoneTestResponse::capture(TestResponse::fromBaseResponse(new Response), $missingSession, 300);

    $this->assertIndistinguishable($existing, $missing);
});

it('fails on a different status', function () {
    $session = new Store('session', new ArraySessionHandler(1));
    $existing = KeystoneTestResponse::capture(TestResponse::fromBaseResponse(new Response('', 302)), $session, 300);
    $missing = KeystoneTestResponse::capture(TestResponse::fromBaseResponse(new Response('', 303)), $session, 300);

    expect(fn () => $this->assertIndistinguishable($existing, $missing))->toThrow(AssertionFailedError::class, 'The responses differ in status.');
});

it('fails on a different redirect', function () {
    $session = new Store('session', new ArraySessionHandler(1));
    $existing = KeystoneTestResponse::capture(TestResponse::fromBaseResponse(new RedirectResponse('/login')), $session, 300);
    $missing = KeystoneTestResponse::capture(TestResponse::fromBaseResponse(new RedirectResponse('/register')), $session, 300);

    expect(fn () => $this->assertIndistinguishable($existing, $missing))->toThrow(AssertionFailedError::class, 'The responses differ in redirect.');
});

it('fails on different flashed data', function () {
    $existingSession = new Store('session', new ArraySessionHandler(1));
    $existingSession->flash('status', 'Check your inbox.');
    $existingSession->ageFlashData();
    $missingSession = new Store('session', new ArraySessionHandler(1));
    $missingSession->flash('status', 'No account uses that address.');
    $missingSession->ageFlashData();
    $existing = KeystoneTestResponse::capture(TestResponse::fromBaseResponse(new Response), $existingSession, 300);
    $missing = KeystoneTestResponse::capture(TestResponse::fromBaseResponse(new Response), $missingSession, 300);

    expect(fn () => $this->assertIndistinguishable($existing, $missing))->toThrow(AssertionFailedError::class, 'The responses differ in flashed data.');
});

it('fails on different flashed-back fields', function () {
    $existingSession = new Store('session', new ArraySessionHandler(1));
    $existingSession->flashInput(['identifier' => 'ada@example.com']);
    $existingSession->ageFlashData();
    $missingSession = new Store('session', new ArraySessionHandler(1));
    $missingSession->flashInput(['identifier' => 'grace@example.com', 'secret' => 'wrong horse']);
    $missingSession->ageFlashData();
    $existing = KeystoneTestResponse::capture(TestResponse::fromBaseResponse(new Response), $existingSession, 300);
    $missing = KeystoneTestResponse::capture(TestResponse::fromBaseResponse(new Response), $missingSession, 300);

    expect(fn () => $this->assertIndistinguishable($existing, $missing))->toThrow(AssertionFailedError::class, 'The responses differ in flashed data.');
});

it('fails on a different header name', function () {
    $session = new Store('session', new ArraySessionHandler(1));
    $existing = KeystoneTestResponse::capture(TestResponse::fromBaseResponse(new Response('', 302, ['X-Account' => 'known'])), $session, 300);
    $missing = KeystoneTestResponse::capture(TestResponse::fromBaseResponse(new Response('', 302)), $session, 300);

    expect(fn () => $this->assertIndistinguishable($existing, $missing))->toThrow(AssertionFailedError::class, 'The responses differ in header names.');
});

it('fails on a different Set-Cookie name', function () {
    $session = new Store('session', new ArraySessionHandler(1));
    $existingBase = new Response;
    $existingBase->headers->setCookie(Cookie::create('app_session', 'one'));
    $existingBase->headers->setCookie(Cookie::create('known_device', 'yes'));
    $missingBase = new Response;
    $missingBase->headers->setCookie(Cookie::create('app_session', 'two'));
    $existing = KeystoneTestResponse::capture(TestResponse::fromBaseResponse($existingBase), $session, 300);
    $missing = KeystoneTestResponse::capture(TestResponse::fromBaseResponse($missingBase), $session, 300);

    expect(fn () => $this->assertIndistinguishable($existing, $missing))->toThrow(AssertionFailedError::class, 'The responses differ in Set-Cookie names.');
});

it('fails on a different time taken on the test clock', function () {
    $session = new Store('session', new ArraySessionHandler(1));
    $existing = KeystoneTestResponse::capture(TestResponse::fromBaseResponse(new Response('', 302)), $session, 300);
    $missing = KeystoneTestResponse::capture(TestResponse::fromBaseResponse(new Response('', 302)), $session, 0);

    expect(fn () => $this->assertIndistinguishable($existing, $missing))->toThrow(AssertionFailedError::class, 'The responses differ in the time they took on the test clock.');
});
