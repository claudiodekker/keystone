<?php

use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Tests\Fixtures\Methods\FakeChallengeType;
use ClaudioDekker\Keystone\Tests\Fixtures\Methods\FakeFormType;
use ClaudioDekker\Keystone\Tests\Fixtures\Methods\FakePinType;

beforeEach(function () {
    $this->types = new CredentialTypes([
        'form' => new FakeFormType,
        'pin' => new FakePinType,
        'challenge-only' => new FakeChallengeType,
    ]);
});

it('lists only the types that serve sign-in, each with its initiate shape', function () {
    $shapes = $this->types->shapes(Surface::SIGN_IN);

    expect($shapes)->toBe([['name' => 'form', 'shape' => 'form'], ['name' => 'pin', 'shape' => 'form']]);
});

it('finds a type only on a surface it serves', function () {
    expect($this->types->find('challenge-only', Surface::SIGN_IN))->toBeNull()
        ->and($this->types->find('challenge-only', Surface::CHALLENGE))->not->toBeNull();
});
