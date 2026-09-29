<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\KeystoneUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

pest()->extend(AppTestCase::class);

function storePassword(Model&KeystoneUser $account, string $hash): int
{
    return DB::table('user_credentials')->insertGetId([
        'user_id' => $account->getKey(),
        'type' => 'password',
        'secret' => Crypt::encryptString($hash),
    ]);
}

it('signs in with a password that starts and ends with spaces', function () {
    $account = $this->createAccount();
    storePassword($account, Hash::make('  correct horse  '));

    $this->post(route('login.submit', ['type' => 'password']), ['identifier' => 'jane@example.com', 'password' => '  correct horse  ']);

    $this->assertAuthenticatedAs($account);
});

it('stores the rehashed password after signing in, without moving the epoch', function () {
    $account = $this->createAccount();
    $id = storePassword($account, password_hash('correct horse', PASSWORD_BCRYPT, ['cost' => 4]));
    config(['hashing.driver' => 'argon2id']);

    $this->post(route('login.submit', ['type' => 'password']), ['identifier' => 'jane@example.com', 'password' => 'correct horse']);

    $this->assertAuthenticatedAs($account);
    $stored = Crypt::decryptString(DB::table('user_credentials')->where('id', $id)->value('secret'));
    expect(password_get_info($stored)['algoName'])->toBe('argon2id')
        ->and(Hash::check('correct horse', $stored))->toBeTrue()
        ->and(DB::table('users')->where('id', $account->getKey())->value('credential_epoch'))->toEqual(0);
});
