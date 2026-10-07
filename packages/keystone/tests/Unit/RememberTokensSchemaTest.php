<?php

use ClaudioDekker\Keystone\Tests\Fixtures\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

function rememberTokenRow(int $userId, string $tokenHash): array
{
    return ['user_id' => $userId, 'token_hash' => $tokenHash, 'credential_epoch' => 0, 'expires_at' => now()->addDay()];
}

it('keeps one remember token per value', function () {
    [$jane, $john] = User::factory()->count(2)->create();
    DB::table('user_remember_tokens')->insert(rememberTokenRow($jane->getKey(), str_repeat('a', 64)));

    DB::table('user_remember_tokens')->insert(rememberTokenRow($john->getKey(), str_repeat('a', 64)));
})->throws(UniqueConstraintViolationException::class);

it('compares remember token hashes as binary', function () {
    $user = User::factory()->create();
    DB::table('user_remember_tokens')->insert(rememberTokenRow($user->getKey(), str_repeat('a', 64)));

    expect(DB::table('user_remember_tokens')->where('token_hash', str_repeat('A', 64))->exists())->toBeFalse();
});

it('forgets an account\'s remember tokens with the account', function () {
    $user = User::factory()->create();
    DB::table('user_remember_tokens')->insert(rememberTokenRow($user->getKey(), str_repeat('a', 64)));

    DB::table('users')->where('id', $user->getKey())->delete();

    $this->assertDatabaseCount('user_remember_tokens', 0);
});
