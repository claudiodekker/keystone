<?php

use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\CredentialTypeRegistry;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\InitiateShape;
use ClaudioDekker\Keystone\Methods\Proof;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Methods\Verification;

beforeEach(function () {
    app(CredentialTypeRegistry::class)->register(new class implements CredentialType
    {
        public function name(): string
        {
            return 'serves-no-sign-in';
        }

        public function initiateShape(Surface $surface): ?InitiateShape
        {
            return null;
        }

        public function isMultiFactor(): bool
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
    $types = collect(app(CredentialTypes::class)->serving(Surface::SIGN_IN))
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

it('lists only the types the allow-list names', function () {
    $type = app(CredentialTypes::class)->serving(Surface::SIGN_IN)[0];
    $shape = $type->initiateShape(Surface::SIGN_IN)->value;
    config(['keystone.methods' => [$type->name()]]);

    $response = $this->get(route('login'));

    $this->assertSignInPage($response, ['types' => [['name' => $type->name(), 'shape' => $shape]]]);
});

it('lists no type when the allow-list names none', function () {
    config(['keystone.methods' => []]);

    $response = $this->get(route('login'));

    $this->assertSignInPage($response, ['types' => []]);
});

it('answers a type the allow-list leaves out as not found', function () {
    $name = app(CredentialTypes::class)->serving(Surface::SIGN_IN)[0]->name();
    config(['keystone.methods' => []]);

    $response = $this->post(route('login.submit', $name), [
        'identifier' => 'ada@example.com',
    ]);

    $this->assertSignInTypeNotFound($response);
});
