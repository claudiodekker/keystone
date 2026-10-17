<?php

use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\InitiateShape;
use ClaudioDekker\Keystone\Methods\Proof;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Methods\Verification;

beforeEach(function () {
    Keystone::credentialType(new class implements CredentialType
    {
        public function name(): string
        {
            return 'serves-no-sign-in';
        }

        public function initiateShape(Surface $surface): ?InitiateShape
        {
            return null;
        }

        public function isMultiFactorOnItsOwn(): bool
        {
            return false;
        }

        public function rules(Surface $surface): array
        {
            return [];
        }

        public function verify(Verification $verification): Proof
        {
            return Proof::rejected();
        }
    });
});

it('lists only the types that serve sign-in, each with its initiate shape', function () {
    $types = collect(Keystone::credentialTypes()->serving(Surface::SIGN_IN))
        ->map(fn (CredentialType $type) => [
            'name' => $type->name(),
            'shape' => $type->initiateShape(Surface::SIGN_IN)->value,
        ])
        ->all();

    $response = $this->get(route('login'));

    $this->assertSignInPage($response, ['types' => $types]);
});

it('answers a type that is not installed as not found', function () {
    $response = $this->post(route('login.submit', 'not-installed'), [
        'identifier' => 'ada@example.com',
    ]);

    $this->assertSignInTypeNotFound($response);
});

it('answers a type that serves no sign-in as not found', function () {
    $response = $this->post(route('login.submit', 'serves-no-sign-in'), [
        'identifier' => 'ada@example.com',
    ]);

    $this->assertSignInTypeNotFound($response);
});
