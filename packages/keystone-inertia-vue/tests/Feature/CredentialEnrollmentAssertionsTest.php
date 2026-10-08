<?php

use ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions\CredentialEnrollmentAssertions;
use ClaudioDekker\Keystone\InertiaVue\Tests\StubsTestCase;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use PHPUnit\Framework\AssertionFailedError;

pest()->extend(StubsTestCase::class)->use(CredentialEnrollmentAssertions::class);

beforeEach(function () {
    $form = ['type' => 'totp', 'shape' => 'form', 'ceremony' => []];

    Route::middleware('web')->group(function () use ($form) {
        Route::get('form', fn () => Inertia::render('settings/CredentialEnrollment', [...$form, 'status' => null]));
        Route::get('restarted-form', fn () => Inertia::render('settings/CredentialEnrollment', [...$form, 'status' => __('keystone::messages.status.enrollment-expired')]));
        Route::get('sign-in-form', fn () => Inertia::render('auth/EnrollmentForm', [...$form, 'status' => __('keystone::messages.status.enrollment-expired')]));
    });
});

it('passes for the page each assertion names', function () {
    $this->assertCredentialEnrollmentForm($this->get('form'), 'totp');
    $this->assertCredentialEnrollmentRestarted($this->get('restarted-form'), 'totp');
});

it('fails for anything else', function (string $assertion, string $uri, string $type) {
    expect(fn () => $this->{$assertion}($this->get($uri), $type))->toThrow(AssertionFailedError::class);
})->with([
    'another type\'s form' => ['assertCredentialEnrollmentForm', 'form', 'code'],
    'the form a sign-in is held at' => ['assertCredentialEnrollmentForm', 'sign-in-form', 'totp'],
    'a form without the expiry' => ['assertCredentialEnrollmentRestarted', 'form', 'totp'],
    'another type\'s restarted form' => ['assertCredentialEnrollmentRestarted', 'restarted-form', 'code'],
    'the restarted form a sign-in is held at' => ['assertCredentialEnrollmentRestarted', 'sign-in-form', 'totp'],
]);
