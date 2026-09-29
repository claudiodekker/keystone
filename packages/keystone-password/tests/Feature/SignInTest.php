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

function signInWithPassword(AppTestCase $test, mixed $password)
{
    return $test->post(route('login.submit', ['type' => 'password']), ['identifier' => 'jane@example.com', 'password' => $password]);
}

function storedPassword(int $id): string
{
    return Crypt::decryptString(DB::table('user_credentials')->where('id', $id)->value('secret'));
}

it('requires the password as a string', function (mixed $password) {
    storePassword($this->createAccount(), Hash::make('correct horse'));

    $response = signInWithPassword($this, $password);

    $response->assertSessionHasErrors('password');
    $this->assertGuest();
})->with([
    'missing' => [null],
    'an array' => [['correct horse']],
]);

it('signs in with a password that starts and ends with spaces', function () {
    $account = $this->createAccount();
    storePassword($account, Hash::make('  correct horse  '));

    signInWithPassword($this, '  correct horse  ');

    $this->assertAuthenticatedAs($account);
});

it('refuses a wrong password, recording the password it was checked against', function () {
    $account = $this->createAccount();
    $id = storePassword($account, Hash::make('correct horse'));

    signInWithPassword($this, 'wrong horse');

    $this->assertGuest();
    $this->assertDatabaseHas('user_security_events', [
        'type' => 'proof.rejected',
        'user_id' => $account->getKey(),
        'credential_id' => $id,
        'reason' => 'password.mismatch',
    ]);
});

it('refuses an account without a usable password', function (?string $hash) {
    $account = $this->createAccount();
    if ($hash !== null) {
        storePassword($account, $hash);
    }

    signInWithPassword($this, 'not a hash');

    $this->assertGuest();
})->with([
    'no password' => [null],
    'a hash of an unknown format' => ['not a hash'],
]);

it('signs in with a password longer than 72 bytes against an imported bcrypt hash of its first 72', function () {
    $account = $this->createAccount();
    $long = str_repeat('a', 72);
    storePassword($account, password_hash($long.'-imported', PASSWORD_BCRYPT, ['cost' => 4]));

    signInWithPassword($this, $long.'-typed');

    $this->assertAuthenticatedAs($account);
});

it('verifies a hash with its own driver and stores it rehashed after signing in, without moving the epoch', function (string $from, array $to, string $algorithm) {
    config(['hashing.driver' => $from]);
    $account = $this->createAccount();
    $id = storePassword($account, Hash::make('correct horse'));
    config(['hashing.bcrypt.verify' => true, 'hashing.argon.verify' => true, ...$to]);
    app('hash')->forgetDrivers();

    signInWithPassword($this, 'correct horse');

    $this->assertAuthenticatedAs($account);
    $stored = storedPassword($id);
    expect(password_get_info($stored)['algoName'])->toBe($algorithm)
        ->and(Hash::needsRehash($stored))->toBeFalse()
        ->and(Hash::check('correct horse', $stored))->toBeTrue()
        ->and(DB::table('users')->where('id', $account->getKey())->value('credential_epoch'))->toEqual(0);
})->with([
    'bcrypt to argon2id' => ['bcrypt', ['hashing.driver' => 'argon2id'], 'argon2id'],
    'argon2i to argon2id' => ['argon', ['hashing.driver' => 'argon2id'], 'argon2id'],
    'argon2id to bcrypt' => ['argon2id', ['hashing.driver' => 'bcrypt'], 'bcrypt'],
    'an older bcrypt cost' => ['bcrypt', ['hashing.bcrypt.rounds' => 5], 'bcrypt'],
]);

it('keeps the stored hash as it is after signing in', function (array $config) {
    $account = $this->createAccount();
    $id = storePassword($account, password_hash('correct horse', PASSWORD_BCRYPT, ['cost' => 4]));
    $stored = DB::table('user_credentials')->where('id', $id)->value('secret');
    config($config);

    signInWithPassword($this, 'correct horse');

    $this->assertAuthenticatedAs($account);
    expect(DB::table('user_credentials')->where('id', $id)->value('secret'))->toBe($stored);
})->with([
    'a current hash' => [[]],
    'rehashing on sign-in turned off' => [['hashing.bcrypt.rounds' => 5, 'hashing.rehash_on_login' => false]],
]);
