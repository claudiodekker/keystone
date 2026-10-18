<?php

use ClaudioDekker\Keystone\Http\Pages\SignInPage;
use ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions\SignInAssertions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use Inertia\Inertia;
use Inertia\ServiceProvider as InertiaServiceProvider;
use PHPUnit\Framework\AssertionFailedError;

use function Orchestra\Testbench\workbench_path;

uses(SignInAssertions::class);

beforeEach(function () {
    $this->app->register(InertiaServiceProvider::class);

    config(['inertia.testing.ensure_pages_exist' => false]);

    View::addLocation(workbench_path('resources', 'views'));
});

it('fails assertSignInPage on a redirect', function () {
    $response = TestResponse::fromBaseResponse(new RedirectResponse(url('/')));

    expect(fn () => $this->assertSignInPage($response))->toThrow(AssertionFailedError::class);
});

it('fails assertSignInPage on a wrong page', function (string $component, array $props, array $fields, string $message) {
    $inertia = Inertia::render($component, $props)->toResponse(Request::create('/login'));
    $response = TestResponse::fromBaseResponse($inertia);

    expect(fn () => $this->assertSignInPage($response, $fields))->toThrow(AssertionFailedError::class, $message);
})->with([
    'another page' => ['auth/Register', ['status' => null, 'types' => []], [], 'Unexpected Inertia page component.'],
    'a page missing a field of the page value' => ['auth/Login', ['types' => []], [], 'Property [status] does not exist.'],
    'a page given the page value as one object' => ['auth/Login', ['page' => new SignInPage(types: [], status: null)], [], 'Property [status] does not exist.'],
    'a page showing another status' => ['auth/Login', ['status' => 'Signed out.', 'types' => []], ['status' => 'Too many attempts.'], 'Property [status] does not match the expected value.'],
]);

it('fails assertSignInPage on a page that leaves the history unencrypted', function () {
    $inertia = Inertia::render('auth/Login', ['status' => null, 'types' => []])->toResponse(Request::create('/login'));
    $response = TestResponse::fromBaseResponse($inertia);

    expect(fn () => $this->assertSignInPage($response))->toThrow(AssertionFailedError::class, 'encrypted');
});
