<?php

use ClaudioDekker\Keystone\Models\UserEmail;

it('adds a verified address', function () {
    $user = $this->createUser();

    $this->addVerifiedAddress($user, 'ada@example.com');

    expect(UserEmail::query()->whereVerified('ada@example.com')->sole()->user->is($user))->toBeTrue();
});

it('adds a made-up verified address when given none, and returns it', function () {
    $user = $this->createUser();

    $address = $this->addVerifiedAddress($user);

    expect(UserEmail::query()->whereVerified($address)->sole()->user->is($user))->toBeTrue();
});

it('adds an unverified address', function () {
    $user = $this->createUser();

    $this->addUnverifiedAddress($user, 'ada@example.com');

    expect(UserEmail::query()->where('address', 'ada@example.com')->sole())
        ->user->is($user)->toBeTrue()
        ->verified_address->toBeNull();
});
