<?php

use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Tests\Fixtures\FormType;

it('finds a registered type by name on a surface it serves', function () {
    $types = new CredentialTypes;
    $types->register($form = new FormType);

    expect($types->find('form', Surface::SIGN_IN))->toBe($form)
        ->and($types->find('form', Surface::CHALLENGE))->toBeNull()
        ->and($types->find('other', Surface::SIGN_IN))->toBeNull();
});

it('lists the types serving a surface', function () {
    $types = new CredentialTypes;
    $types->register($form = new FormType);
    $types->register($both = new FormType(name: 'both', surfaces: ['sign-in', 'challenge']));
    $types->register(new FormType(name: 'second', surfaces: ['challenge']));

    expect($types->serving(Surface::SIGN_IN))->toBe([$form, $both]);
});

it('refuses a second type with a taken name', function (string $name) {
    $types = new CredentialTypes;
    $types->register(new FormType);

    $types->register(new FormType(name: $name));
})->with(['form', 'recovery-code'])->throws(LogicException::class, 'is already taken');
