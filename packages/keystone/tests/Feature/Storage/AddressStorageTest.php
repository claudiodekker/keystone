<?php

// Storage shape: whether two addresses collide is the database's collation and indexes, on each engine.

use ClaudioDekker\Keystone\Models\UserEmail;
use ClaudioDekker\Keystone\Tests\Fixtures\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

it('keeps two addresses that differ only by an accent apart, on every engine', function () {
    UserEmail::factory()->for(User::factory(), 'user')->verified()->create(['address' => 'rené@example.com']);

    $email = UserEmail::factory()->for(User::factory(), 'user')->verified()->create(['address' => 'rene@example.com']);

    expect(UserEmail::query()->whereVerified('rene@example.com')->sole()->is($email))->toBeTrue();
});

it('lets one account at a time hold an address verified', function () {
    UserEmail::factory()->for(User::factory(), 'user')->verified()->create(['address' => 'ada@example.com']);

    $second = fn () => UserEmail::factory()->for(User::factory(), 'user')->verified()->create(['address' => 'ada@example.com']);

    expect($second)->toThrow(UniqueConstraintViolationException::class);
});

it('lets an account hold an address once', function () {
    $user = User::factory()->create();
    UserEmail::factory()->for($user, 'user')->create(['address' => 'ada@example.com']);

    $second = fn () => UserEmail::factory()->for($user, 'user')->create(['address' => 'ada@example.com']);

    expect($second)->toThrow(UniqueConstraintViolationException::class);
});
