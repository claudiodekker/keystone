<?php

use ClaudioDekker\Keystone\Methods\InitiateShape;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Password\PasswordType;

beforeEach(function () {
    $this->type = new PasswordType(config());
});

it('serves sign-in, registration and enrollment with a form, and not the challenge', function () {
    expect($this->type->initiateShape(Surface::SIGN_IN))->toBe(InitiateShape::FORM)
        ->and($this->type->initiateShape(Surface::REGISTRATION))->toBe(InitiateShape::FORM)
        ->and($this->type->initiateShape(Surface::ENROLLMENT))->toBe(InitiateShape::FORM)
        ->and($this->type->initiateShape(Surface::CHALLENGE))->toBeNull();
});

it('is not multi-factor on its own', function () {
    expect($this->type->isMultiFactorOnItsOwn())->toBeFalse();
});
