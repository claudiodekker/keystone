<?php

use ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions\SignOutAssertions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Testing\TestResponse;
use Inertia\ServiceProvider as InertiaServiceProvider;
use PHPUnit\Framework\AssertionFailedError;

uses(SignOutAssertions::class);

beforeEach(function () {
    $this->app->register(InertiaServiceProvider::class);
});

it('fails assertSignedOut on a sign-out that does not clear the history', function () {
    $response = TestResponse::fromBaseResponse(new RedirectResponse(route('login')));

    expect(fn () => $this->assertSignedOut($response))->toThrow(AssertionFailedError::class, 'clear the browser history');
});
