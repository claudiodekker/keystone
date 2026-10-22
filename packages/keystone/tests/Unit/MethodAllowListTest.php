<?php

use ClaudioDekker\Keystone\Methods\MethodAllowList;
use ClaudioDekker\Keystone\Methods\Surface;

it('allows every method on every surface when there is no list', function () {
    $list = MethodAllowList::from(null);

    expect($list->allows('password', Surface::SIGN_IN))->toBeTrue()
        ->and($list->allows('totp', Surface::CHALLENGE))->toBeTrue()
        ->and($list->problems)->toBe([]);
});

it('allows a bare entry on every surface and a surface list on those surfaces only', function () {
    $list = MethodAllowList::from(['password', 'totp' => ['challenge', 'enrollment']]);

    expect($list->allows('password', Surface::SIGN_IN))->toBeTrue()
        ->and($list->allows('password', Surface::REGISTRATION))->toBeTrue()
        ->and($list->allows('totp', Surface::CHALLENGE))->toBeTrue()
        ->and($list->allows('totp', Surface::ENROLLMENT))->toBeTrue()
        ->and($list->allows('totp', Surface::SIGN_IN))->toBeFalse()
        ->and($list->problems)->toBe([]);
});

it('allows a method the list leaves out nowhere', function () {
    $list = MethodAllowList::from(['password']);

    expect($list->allows('totp', Surface::CHALLENGE))->toBeFalse()
        ->and($list->names())->toBe(['password']);
});

it('allows nothing when the list is empty', function () {
    $list = MethodAllowList::from([]);

    expect($list->allows('password', Surface::SIGN_IN))->toBeFalse()
        ->and($list->names())->toBe([])
        ->and($list->problems)->toBe([]);
});

it('says which surfaces an entry narrows to', function () {
    $list = MethodAllowList::from(['password', 'totp' => ['enrollment', 'challenge']]);

    expect($list->surfaces('password'))->toBeNull()
        ->and($list->surfaces('totp'))->toBe([Surface::ENROLLMENT, Surface::CHALLENGE]);
});

it('lists nothing and reports a value that is not a list', function (mixed $value) {
    $list = MethodAllowList::from($value);

    expect($list->allows('password', Surface::SIGN_IN))->toBeFalse()
        ->and($list->problems)->toBe(['keystone.methods must be null, or a list of credential types by name, each optionally with the list of its surfaces.']);
})->with([
    'a string' => 'password',
    'true' => true,
    'an integer' => 1,
]);

it('lists nothing for, and reports, an entry that is malformed', function (array $entry) {
    $key = array_key_first($entry);

    $list = MethodAllowList::from($entry);

    expect($list->names())->toBe([])
        ->and($list->problems)->toBe(["keystone.methods entry [{$key}] must be a credential type's name, or its name with a non-empty list of surfaces from challenge, enrollment, registration and sign-in."]);
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

it('reports a method named twice and lists it once', function () {
    $list = MethodAllowList::from(['password', 'totp' => ['challenge'], 'password' => ['sign-in']]);

    expect($list->names())->toBe(['password', 'totp'])
        ->and($list->problems)->toBe(['keystone.methods names [password] twice.']);
});
