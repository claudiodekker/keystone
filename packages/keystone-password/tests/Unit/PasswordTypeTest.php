<?php

use ClaudioDekker\Keystone\Methods\Credential;
use ClaudioDekker\Keystone\Methods\InitiateShape;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Methods\Verification;
use ClaudioDekker\Keystone\Password\DummyHash;
use ClaudioDekker\Keystone\Password\PasswordType;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->type = new PasswordType(config(), new DummyHash(config()));
});

it('serves sign-in, registration and enrollment with a form, and not the challenge', function () {
    expect($this->type->initiateShape(Surface::SIGN_IN))->toBe(InitiateShape::FORM)
        ->and($this->type->initiateShape(Surface::REGISTRATION))->toBe(InitiateShape::FORM)
        ->and($this->type->initiateShape(Surface::ENROLLMENT))->toBe(InitiateShape::FORM)
        ->and($this->type->initiateShape(Surface::CHALLENGE))->toBeNull();
});

it('is not multi-factor', function () {
    expect($this->type->isMultiFactor())->toBeFalse();
});

it('takes as long to refuse an unknown account as a wrong password', function () {
    config(['hashing.bcrypt.rounds' => 10]);
    Hash::forgetDrivers();
    $type = new PasswordType(config(), new DummyHash(config()));
    $known = [new Credential(1, Hash::make('correct horse'))];
    $takes = function (array $credentials) use ($type): int {
        $verification = new Verification(Surface::SIGN_IN, ['password' => 'wrong horse'], $credentials);
        $start = hrtime(true);
        $type->verify($verification);

        return hrtime(true) - $start;
    };

    $takes([]);

    $unknown = collect(range(1, 7))->map(fn () => $takes([]))->median();
    $wrong = collect(range(1, 7))->map(fn () => $takes($known))->median();

    expect($unknown / $wrong)->toBeBetween(0.5, 2.0);
});
