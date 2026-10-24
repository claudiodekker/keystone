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
