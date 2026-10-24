<?php

use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\MethodAllowList;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Tests\Fixtures\Methods\FakeChallengeType;
use ClaudioDekker\Keystone\Tests\Fixtures\Methods\FakeFormType;
use ClaudioDekker\Keystone\Tests\Fixtures\Methods\FakePinType;
use ClaudioDekker\Keystone\Tests\Fixtures\Methods\FakeSurfacesType;

beforeEach(function () {
    $this->registered = [
        'form' => new FakeFormType,
        'pin' => new FakePinType,
        'challenge-only' => new FakeChallengeType,
        'everywhere' => new FakeSurfacesType('everywhere', [Surface::CHALLENGE, Surface::SIGN_IN]),
    ];

    $this->types = new CredentialTypes($this->registered, MethodAllowList::from(null), []);
});

it('lists only the types that serve sign-in, each with its initiate shape', function () {
    $shapes = $this->types->shapes(Surface::SIGN_IN);

    expect($shapes)->toBe([
        ['name' => 'form', 'shape' => 'form'],
        ['name' => 'pin', 'shape' => 'form'],
        ['name' => 'everywhere', 'shape' => 'form'],
    ]);
});

it('finds a type only on a surface it serves', function () {
    expect($this->types->find('challenge-only', Surface::SIGN_IN))->toBeNull()
        ->and($this->types->find('challenge-only', Surface::CHALLENGE))->not->toBeNull();
});

it("offers the fixture app's types before any installed method package's", function () {
    $types = Keystone::credentialTypes()->serving(Surface::SIGN_IN);

    expect($types[0])->toBeInstanceOf(FakeFormType::class);
});

it('offers a type only on the surfaces the allow-list names for it', function () {
    $types = new CredentialTypes($this->registered, MethodAllowList::from(['form', 'everywhere' => ['challenge']]), []);

    expect($types->find('everywhere', Surface::SIGN_IN))->toBeNull()
        ->and($types->find('everywhere', Surface::CHALLENGE))->not->toBeNull()
        ->and($types->find('form', Surface::SIGN_IN))->not->toBeNull()
        ->and($types->shapes(Surface::SIGN_IN))->toBe([['name' => 'form', 'shape' => 'form']])
        ->and($types->shapes(Surface::CHALLENGE))->toBe([['name' => 'everywhere', 'shape' => 'form']]);
});

it('offers a type the allow-list leaves out nowhere', function () {
    $types = new CredentialTypes($this->registered, MethodAllowList::from(['form']), []);

    expect($types->find('pin', Surface::SIGN_IN))->toBeNull()
        ->and($types->find('challenge-only', Surface::CHALLENGE))->toBeNull()
        ->and($types->names())->toBe(['form'])
        ->and($types->serving(Surface::SIGN_IN))->toBe([$this->registered['form']])
        ->and($types->serving(Surface::CHALLENGE))->toBe([]);
});

it('keeps a type it is told to narrow to a surface it does not serve unoffered there', function () {
    $types = new CredentialTypes($this->registered, MethodAllowList::from(['form' => ['challenge']]), []);

    expect($types->find('form', Surface::CHALLENGE))->toBeNull()
        ->and($types->names())->toBe(['form']);
});

it('still gives the registered type behind a name the allow-list leaves out', function () {
    $types = new CredentialTypes($this->registered, MethodAllowList::from([]), []);

    expect($types->registered('pin'))->toBe($this->registered['pin'])
        ->and($types->registered('missing'))->toBeNull();
});
