<?php

use ClaudioDekker\Keystone\Methods\CredentialTypeRegistry;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Tests\Fixtures\Methods\FakeSurfacesType;

it('keeps the first type registered under a name', function () {
    $registry = new CredentialTypeRegistry;
    $first = new FakeSurfacesType('clash', [Surface::SIGN_IN]);
    $second = new FakeSurfacesType('clash', [Surface::CHALLENGE]);

    $registry->register($first);
    $registry->register($second);

    expect($registry->types())->toBe(['clash' => $first]);
});

it('holds every type registered under a name more than one package registers', function () {
    $registry = new CredentialTypeRegistry;
    $first = new FakeSurfacesType('clash', [Surface::SIGN_IN]);
    $second = new FakeSurfacesType('clash', [Surface::CHALLENGE]);
    $alone = new FakeSurfacesType('alone', [Surface::SIGN_IN]);

    $registry->register($first);
    $registry->register($alone);
    $registry->register($second);

    expect($registry->clashes())->toBe(['clash' => [$first, $second]]);
});
