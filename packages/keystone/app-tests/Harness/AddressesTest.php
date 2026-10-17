<?php

use PHPUnit\Framework\AssertionFailedError;

it('adds a verified address', function () {
    $user = $this->createUser();

    $this->addVerifiedAddress($user, 'ada@example.com');

    $this->assertAddressVerified($user, 'ada@example.com');
});

it('adds a made-up verified address when given none, and returns it', function () {
    $user = $this->createUser();

    $address = $this->addVerifiedAddress($user);

    $this->assertAddressVerified($user, $address);
});

it('adds an unverified address', function () {
    $user = $this->createUser();

    $this->addUnverifiedAddress($user, 'ada@example.com');

    $this->assertAddressUnverified($user, 'ada@example.com');
});

it('fails assertAddressVerified on an unverified address', function () {
    $user = $this->createUser();
    $this->addUnverifiedAddress($user, 'ada@example.com');

    expect(fn () => $this->assertAddressVerified($user, 'ada@example.com'))->toThrow(AssertionFailedError::class, 'The user holds no verified [ada@example.com].');
});

it("fails assertAddressVerified on another account's verified address", function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($this->createUser(), 'ada@example.com');

    expect(fn () => $this->assertAddressVerified($user, 'ada@example.com'))->toThrow(AssertionFailedError::class, 'The user holds no verified [ada@example.com].');
});

it('fails assertAddressUnverified on a verified address', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');

    expect(fn () => $this->assertAddressUnverified($user, 'ada@example.com'))->toThrow(AssertionFailedError::class, 'The user holds no unverified [ada@example.com].');
});

it("fails assertAddressUnverified on another account's unverified address", function () {
    $user = $this->createUser();
    $this->addUnverifiedAddress($this->createUser(), 'ada@example.com');

    expect(fn () => $this->assertAddressUnverified($user, 'ada@example.com'))->toThrow(AssertionFailedError::class, 'The user holds no unverified [ada@example.com].');
});
