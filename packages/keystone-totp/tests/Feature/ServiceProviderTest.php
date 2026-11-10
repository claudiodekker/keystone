<?php

use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\OneTimeCode;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Totp\TotpType;

it('registers totp as a plain instance it never binds, counted as a one-time code', function () {
    $type = app(CredentialTypes::class)->find('totp', Surface::CHALLENGE);

    expect($type)->toBeInstanceOf(TotpType::class)
        ->toBeInstanceOf(OneTimeCode::class)
        ->and($this->app->bound(TotpType::class))->toBeFalse();
});

it('offers totp on the challenge alone', function () {
    $types = app(CredentialTypes::class);

    expect($types->find('totp', Surface::SIGN_IN))->toBeNull()
        ->and($types->find('totp', Surface::REGISTRATION))->toBeNull()
        ->and($types->find('totp', Surface::ENROLLMENT))->toBeNull();
});

it('accepts one step either side of now by default', function () {
    expect(config('keystone.totp.window_steps'))->toBe(1);
});

it('refuses to boot with a window that is not a whole number of at least 0', function (mixed $window) {
    config(['keystone.totp.window_steps' => $window]);

    expect($this->bootFailures())->toBe(['keystone.totp.window_steps must be a whole number of steps of at least 0.']);
})->with([
    'negative' => [-1],
    'a fraction' => [1.5],
    'a numeric string' => ['1'],
    'null' => [null],
]);

it('boots with a window of 0 or a wide one', function (int $window) {
    config(['keystone.totp.window_steps' => $window]);

    expect($this->bootFailures())->toBe([]);
})->with([
    'zero' => [0],
    'wide' => [40],
]);
