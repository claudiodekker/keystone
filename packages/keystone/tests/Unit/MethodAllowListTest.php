<?php

use ClaudioDekker\Keystone\Methods\AllowListEntry;
use ClaudioDekker\Keystone\Methods\MethodAllowList;
use ClaudioDekker\Keystone\Methods\Surface;

it('allows every type on every surface when there is no list', function () {
    $list = MethodAllowList::from(null);

    expect($list->allows('password', Surface::SIGN_IN))->toBeTrue()
        ->and($list->allows('totp', Surface::CHALLENGE))->toBeTrue();
});

it('allows a bare entry on every surface and a surface list on those surfaces only', function () {
    $list = MethodAllowList::from(['password', 'totp' => ['challenge', 'enrollment']]);

    expect($list->allows('password', Surface::SIGN_IN))->toBeTrue()
        ->and($list->allows('password', Surface::REGISTRATION))->toBeTrue()
        ->and($list->allows('totp', Surface::CHALLENGE))->toBeTrue()
        ->and($list->allows('totp', Surface::ENROLLMENT))->toBeTrue()
        ->and($list->allows('totp', Surface::SIGN_IN))->toBeFalse();
});

it('allows a type the list leaves out nowhere', function () {
    $list = MethodAllowList::from(['password']);

    expect($list->allows('totp', Surface::CHALLENGE))->toBeFalse()
        ->and($list->entries())->toEqual([new AllowListEntry('password', null)]);
});

it('allows nothing when the list is empty', function () {
    $list = MethodAllowList::from([]);

    expect($list->allows('password', Surface::SIGN_IN))->toBeFalse()
        ->and($list->entries())->toBe([]);
});

it('says which surfaces an entry narrows to', function () {
    $list = MethodAllowList::from(['password', 'totp' => ['enrollment', 'challenge']]);

    expect($list->entries())->toEqual([
        new AllowListEntry('password', null),
        new AllowListEntry('totp', [Surface::ENROLLMENT, Surface::CHALLENGE]),
    ]);
});
