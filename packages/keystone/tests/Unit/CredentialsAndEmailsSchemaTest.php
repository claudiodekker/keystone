<?php

use ClaudioDekker\Keystone\Tests\Fixtures\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

function emailRow(int $userId, string $address, ?string $verifiedAddress = null): array
{
    return ['user_id' => $userId, 'address' => $address, 'verified_address' => $verifiedAddress, 'is_primary' => false];
}

function credentialRow(int $userId, ?string $identifierHash): array
{
    return ['user_id' => $userId, 'type' => 'form', 'identifier_hash' => $identifierHash, 'secret' => 'encrypted'];
}

it('lets several accounts hold one address', function () {
    [$jane, $john] = User::factory()->count(2)->create();

    DB::table('user_emails')->insert([emailRow($jane->getKey(), 'shared@example.com'), emailRow($john->getKey(), 'shared@example.com')]);

    $this->assertDatabaseCount('user_emails', 2);
});

it('lets only one account hold an address as verified', function () {
    [$jane, $john] = User::factory()->count(2)->create();
    DB::table('user_emails')->insert(emailRow($jane->getKey(), 'jane@example.com', 'jane@example.com'));

    DB::table('user_emails')->insert(emailRow($john->getKey(), 'jane@example.com', 'jane@example.com'));
})->throws(UniqueConstraintViolationException::class);

it('compares addresses as binary', function () {
    $user = User::factory()->create();
    DB::table('user_emails')->insert(emailRow($user->getKey(), 'rené@example.com', 'rené@example.com'));

    expect(DB::table('user_emails')->where('address', 'RENÉ@example.com')->exists())->toBeFalse()
        ->and(DB::table('user_emails')->where('verified_address', 'rene@example.com')->exists())->toBeFalse()
        ->and(DB::table('user_emails')->where('address', 'rené@example.com')->exists())->toBeTrue();
});

it('keeps one identifier per credential type', function () {
    $user = User::factory()->create();
    DB::table('user_credentials')->insert(credentialRow($user->getKey(), 'hash'));

    DB::table('user_credentials')->insert(credentialRow($user->getKey(), 'hash'));
})->throws(UniqueConstraintViolationException::class);

it('allows several credentials without an identifier', function () {
    $user = User::factory()->create();

    DB::table('user_credentials')->insert([credentialRow($user->getKey(), null), credentialRow($user->getKey(), null)]);

    $this->assertDatabaseCount('user_credentials', 2);
});

it('compares identifier hashes as binary', function () {
    $user = User::factory()->create();
    DB::table('user_credentials')->insert(credentialRow($user->getKey(), 'abc'));

    expect(DB::table('user_credentials')->where('identifier_hash', 'ABC')->exists())->toBeFalse();
});
