<?php

use ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions\RegistrationLinkAssertions;
use ClaudioDekker\Keystone\InertiaVue\Tests\StubsTestCase;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use PHPUnit\Framework\AssertionFailedError;

pest()->extend(StubsTestCase::class)->use(RegistrationLinkAssertions::class);

beforeEach(function () {
    Route::middleware('web')->group(function () {
        Route::get('link-page', fn () => Inertia::render('auth/EmailedLink', ['action' => '/auth/register/verify?token=x']));
        Route::get('link-expired-page', fn () => Inertia::render('auth/RegisterLinkExpired'));
    });
});

it('passes for the pages the assertions name', function () {
    $this->assertRegistrationLinkPage($this->get('link-page'));
    $this->assertRegistrationLinkExpiredPage($this->get('link-expired-page'));
});

it('fails the link\'s step for anything else', function () {
    expect(fn () => $this->assertRegistrationLinkPage($this->get('link-expired-page')))->toThrow(AssertionFailedError::class);
});

it('fails the link-expired page for anything else', function () {
    expect(fn () => $this->assertRegistrationLinkExpiredPage($this->get('link-page')))->toThrow(AssertionFailedError::class);
});
