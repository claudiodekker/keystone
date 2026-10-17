<?php

use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\Surface;

it('lists only the types that serve sign-in, each with its initiate shape', function () {
    $shapes = Keystone::credentialTypes()->shapes(Surface::SIGN_IN);

    expect($shapes)->toBe([['name' => 'form', 'shape' => 'form'], ['name' => 'pin', 'shape' => 'form']]);
});

it('finds a type only on a surface it serves', function () {
    $types = Keystone::credentialTypes();

    expect($types->find('challenge-only', Surface::SIGN_IN))->toBeNull()
        ->and($types->find('challenge-only', Surface::CHALLENGE))->not->toBeNull();
});
