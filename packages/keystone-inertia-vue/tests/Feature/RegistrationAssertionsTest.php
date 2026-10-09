<?php

use ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions\RegistrationAssertions;
use ClaudioDekker\Keystone\InertiaVue\Tests\StubsTestCase;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use PHPUnit\Framework\AssertionFailedError;

pest()->extend(StubsTestCase::class)->use(RegistrationAssertions::class);

beforeEach(function () {
    Route::middleware('web')->group(function () {
        Route::get('register-page', fn () => Inertia::render('auth/Register', ['status' => null, 'email' => null]));
        Route::get('link-sent-page', fn () => Inertia::render('auth/RegisterLinkSent'));
    });
});

it('passes for the pages the assertions name', function () {
    $this->assertRegistrationPage($this->get('register-page'));
    $this->assertRegistrationLinkSentPage($this->get('link-sent-page'));
});

it('fails the register page for anything else', function () {
    expect(fn () => $this->assertRegistrationPage($this->get('link-sent-page')))->toThrow(AssertionFailedError::class);
});

it('fails the link-sent page for anything else', function () {
    expect(fn () => $this->assertRegistrationLinkSentPage($this->get('register-page')))->toThrow(AssertionFailedError::class);
});
