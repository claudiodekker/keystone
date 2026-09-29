<?php

use ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions\SignInAssertions;
use ClaudioDekker\Keystone\InertiaVue\Tests\StubsTestCase;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use PHPUnit\Framework\AssertionFailedError;

pest()->extend(StubsTestCase::class)->use(SignInAssertions::class);

beforeEach(function () {
    Route::middleware('web')->group(function () {
        Route::get('sign-in-page', fn () => Inertia::render('keystone/SignIn', ['types' => [], 'status' => 'Signed out.']));
        Route::get('other-component', fn () => Inertia::render('keystone/Other', ['types' => [], 'status' => 'Signed out.']));
        Route::get('no-types', fn () => Inertia::render('keystone/SignIn', ['status' => 'Signed out.']));
    });
});

it('passes for the sign-in page', function (?string $status) {
    $this->assertSignInPage($this->get('sign-in-page'), $status);
})->with(['without a status' => [null], 'with its status' => ['Signed out.']]);

it('fails for anything else', function (string $uri, ?string $status) {
    expect(fn () => $this->assertSignInPage($this->get($uri), $status))->toThrow(AssertionFailedError::class);
})->with([
    'another component' => ['other-component', null],
    'no types' => ['no-types', null],
    'another status' => ['sign-in-page', 'Welcome back.'],
]);
