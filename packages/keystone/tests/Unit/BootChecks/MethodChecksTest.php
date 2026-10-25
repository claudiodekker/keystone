<?php

use ClaudioDekker\Keystone\Methods\CredentialTypeRegistry;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Tests\Fixtures\Methods\FakeFormType;
use ClaudioDekker\Keystone\Tests\Fixtures\Methods\FakeSurfacesType;

it('boots with a list that narrows what the installed types serve', function (array $methods) {
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

it('refuses a malformed entry', function (array $methods) {
    $key = array_key_first($methods);
    config(['keystone.methods' => $methods]);

    expect($this->bootFailures())->toBe(["keystone.methods entry [{$key}] must be a credential type's name, or its name with a non-empty list of surfaces from challenge, enrollment, registration and sign-in."]);
})->with([
    'a name that is not a string' => [[5 => 7]],
    'a name that is empty' => [['']],
    'surfaces that are a string' => [['password' => 'sign-in']],
    'surfaces that are not a list' => [['password' => ['a' => 'sign-in']]],
    'no surfaces' => [['password' => []]],
    'a surface that does not exist' => [['password' => ['sign_in']]],
    'a surface that is not a string' => [['password' => [1]]],
    'a null' => [['password' => null]],
]);

it('refuses a list that is malformed, once for each entry', function () {
    config(['keystone.methods' => ['form', 5 => 7, 'pin' => []]]);

    $failures = $this->bootFailures();

    expect($failures)->toBe([
        "keystone.methods entry [5] must be a credential type's name, or its name with a non-empty list of surfaces from challenge, enrollment, registration and sign-in.",
        "keystone.methods entry [pin] must be a credential type's name, or its name with a non-empty list of surfaces from challenge, enrollment, registration and sign-in.",
    ]);
});

it('refuses a value that is not a list', function (mixed $value) {
    config(['keystone.methods' => $value]);

    expect($this->bootFailures())->toBe(['keystone.methods must be null, or a list of credential types by name, each optionally with the list of its surfaces.']);
})->with([
    'a string' => 'password',
    'true' => true,
    'an integer' => 1,
]);

it('refuses two packages that register one name, whatever the list holds', function () {
    app(CredentialTypeRegistry::class)->register(new FakeSurfacesType('form', [Surface::CHALLENGE]));

    expect($this->bootFailures())->toBe([
        'Two packages register the credential type [form]: '.FakeFormType::class.' and '.FakeSurfacesType::class.'.',
    ]);
});
