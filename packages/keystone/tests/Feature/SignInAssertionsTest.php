<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\AppTests\Assertions\SignInAssertions;
use ClaudioDekker\Keystone\Status;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\AssertionFailedError;

pest()->extend(AppTestCase::class)->use(SignInAssertions::class);

beforeEach(function () {
    Route::get('sign-in-page', fn () => 'Sign in');
    Route::get('sign-in-page-with-status', fn () => 'Sign in. '.Status::SIGNED_OUT->label());
});

it('passes for a sign-in page without a status', function () {
    $this->assertSignInPage($this->get('sign-in-page'));
});

it('passes for a sign-in page showing the status it is given', function () {
    $this->assertSignInPage($this->get('sign-in-page-with-status'), Status::SIGNED_OUT->label());
});

it('fails for a status when none is given', function (Status $status) {
    Route::get('status-page', fn () => 'Sign in. '.$status->label());

    expect(fn () => $this->assertSignInPage($this->get('status-page')))->toThrow(AssertionFailedError::class);
})->with(Status::cases());

it('fails for no status when one is given', function () {
    expect(fn () => $this->assertSignInPage($this->get('sign-in-page'), Status::SIGNED_OUT->label()))->toThrow(AssertionFailedError::class);
});
