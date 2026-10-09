<?php

use ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions\RegistrationFinishAssertions;
use ClaudioDekker\Keystone\InertiaVue\Tests\StubsTestCase;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use PHPUnit\Framework\AssertionFailedError;

pest()->extend(StubsTestCase::class)->use(RegistrationFinishAssertions::class);

beforeEach(function () {
    Route::middleware('web')->group(function () {
        Route::get('finish-page', fn () => Inertia::render('auth/RegisterFinish', ['address' => 'new@example.com', 'types' => [], 'status' => null]));
        Route::get('register-page', fn () => Inertia::render('auth/Register', ['status' => null, 'email' => null]));
    });
});

it('passes for the page the assertion names', function () {
    $this->assertRegistrationFinishPage($this->get('finish-page'), 'new@example.com');
});

it('fails for anything else', function (string $uri, string $address) {
    expect(fn () => $this->assertRegistrationFinishPage($this->get($uri), $address))->toThrow(AssertionFailedError::class);
})->with([
    'another address' => ['finish-page', 'other@example.com'],
    'the register page' => ['register-page', 'new@example.com'],
]);
