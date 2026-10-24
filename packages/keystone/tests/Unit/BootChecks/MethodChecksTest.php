<?php

use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Tests\Fixtures\Methods\FakeFormType;
use ClaudioDekker\Keystone\Tests\Fixtures\Methods\FakeSurfacesType;

it('boots with a list that narrows what the installed types declare', function (array $methods) {
    config(['keystone.methods' => $methods]);

    expect($this->bootFailures())->toBe([]);
})->with([
    'bare names' => [['form', 'challenge-only']],
    'a name with the surfaces it serves' => [['form' => ['sign-in'], 'challenge-only' => ['challenge']]],
    'no types at all' => [[]],
]);

it('refuses a surface the type does not serve', function () {
    config(['keystone.methods' => ['form' => ['sign-in', 'challenge']]]);

    expect($this->bootFailures())->toBe(["keystone.methods lists [form] on [challenge], which that type doesn't serve."]);
});

it('refuses a type named twice', function () {
    config(['keystone.methods' => ['form', 'form']]);

    expect($this->bootFailures())->toBe(['keystone.methods names [form] twice.']);
});

it('refuses a type that no package registers', function () {
    config(['keystone.methods' => ['form', 'nobody', 'someone' => ['sign-in']]]);

    expect($this->bootFailures())->toBe([
        'keystone.methods names [nobody], and no installed package registers a credential type with that name.',
        'keystone.methods names [someone], and no installed package registers a credential type with that name.',
    ]);
});

it('refuses a list that is malformed, once for each entry', function () {
    config(['keystone.methods' => ['form', 5 => 7, 'pin' => []]]);

    expect($this->bootFailures())->toHaveCount(2)
        ->and($this->bootFailures()[0])->toStartWith('keystone.methods entry [5] must be')
        ->and($this->bootFailures()[1])->toStartWith('keystone.methods entry [pin] must be');
});

it('refuses a value that is not a list', function () {
    config(['keystone.methods' => 'password']);

    expect($this->bootFailures())->toBe(['keystone.methods must be null, or a list of credential types by name, each optionally with the list of its surfaces.']);
});

it('refuses two packages that register one name, whatever the list holds', function () {
    Keystone::credentialType(new FakeSurfacesType('form', [Surface::CHALLENGE]));

    expect($this->bootFailures())->toBe([
        'Two packages register the credential type [form]: '.FakeFormType::class.' and '.FakeSurfacesType::class.'.',
    ]);
});
