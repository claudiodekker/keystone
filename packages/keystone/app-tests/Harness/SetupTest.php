<?php

use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\AssertionFailedError;

it('names the fix when the user model has no factory', function () {
    $model = new class extends Authenticatable implements KeystoneUser {};
    config(['auth.providers.users.model' => $model::class]);
    Auth::forgetGuards();

    expect(fn () => $this->createUser())->toThrow(AssertionFailedError::class, 'has no factory. Give it HasFactory and a factory, as Keystone takes its users from the app.');
});

it('names the fix when no installed type with test support serves the surface', function () {
    Keystone::flushState();

    expect(fn () => $this->supportFor(Surface::SIGN_IN))->toThrow(AssertionFailedError::class, 'No installed credential type with test support serves [sign-in]. Install a method package that does.');
});
