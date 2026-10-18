<?php

use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\InitiateShape;
use ClaudioDekker\Keystone\Methods\Proof;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Methods\Verification;
use ClaudioDekker\Keystone\SignIn\SignIn;
use Illuminate\Validation\ValidationException;

it("keeps core's identifier rule when a type's rules loosen it", function () {
    $type = new class implements CredentialType
    {
        public function name(): string
        {
            return 'loosening';
        }

        public function initiateShape(Surface $surface): ?InitiateShape
        {
            return InitiateShape::FORM;
        }

        public function isMultiFactor(): bool
        {
            return false;
        }

        public function rules(Surface $surface): array
        {
            return ['identifier' => ['nullable']];
        }

        public function verify(Verification $verification): Proof
        {
            return Proof::rejected();
        }
    };

    $attempt = fn () => $this->app->make(SignIn::class)->attempt($type, []);

    expect($attempt)->toThrow(ValidationException::class);
});
