<?php

use ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions\SignInAssertions;
use ClaudioDekker\Keystone\InertiaVue\Tests\StubsTestCase;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use PHPUnit\Framework\AssertionFailedError;

pest()->extend(StubsTestCase::class)->use(SignInAssertions::class);

beforeEach(function () {
    Route::middleware('web')->group(function () {
        Route::get('sign-in-page', fn () => Inertia::render('auth/Login', ['types' => [], 'status' => 'Signed out.']));
        Route::get('sign-in-page-without-status', fn () => Inertia::render('auth/Login', ['types' => [], 'status' => null]));
        Route::get('other-component', fn () => Inertia::render('auth/Other', ['types' => [], 'status' => 'Signed out.']));
        Route::get('no-types', fn () => Inertia::render('auth/Login', ['status' => 'Signed out.']));
    });
});

it('passes for the sign-in page', function (string $uri, ?string $status) {
    $this->assertSignInPage($this->get($uri), $status);
})->with([
    'without a status' => ['sign-in-page-without-status', null],
    'with its status' => ['sign-in-page', 'Signed out.'],
]);

it('fails for anything else', function (string $uri, ?string $status) {
    expect(fn () => $this->assertSignInPage($this->get($uri), $status))->toThrow(AssertionFailedError::class);
})->with([
    'another component' => ['other-component', null],
    'no types' => ['no-types', null],
    'another status' => ['sign-in-page', 'Welcome back.'],
    'a status when none is given' => ['sign-in-page', null],
    'no status when one is given' => ['sign-in-page-without-status', 'Signed out.'],
]);
