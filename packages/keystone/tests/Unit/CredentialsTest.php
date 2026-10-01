<?php

use ClaudioDekker\Keystone\Credentials;
use ClaudioDekker\Keystone\Methods\StoredCredential;
use ClaudioDekker\Keystone\Tests\Fixtures\FormType;
use ClaudioDekker\Keystone\Tests\Fixtures\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

function credentials(): Credentials
{
    return new Credentials(new User);
}

it('stores a credential with its identifier and secret encrypted', function () {
    $user = User::factory()->create();

    credentials()->store($user, new FormType, identifier: 'jane-form', secret: 'hashed-secret', label: 'My form');

    $row = DB::table('user_credentials')->sole();
    expect($row)
        ->user_id->toEqual($user->getKey())
        ->type->toBe('form')
        ->identifier_hash->toBe(hash('sha256', 'jane-form'))
        ->label->toBe('My form')
        ->served_challenge->toEqual(0)
        ->and(Crypt::decryptString($row->identifier))->toBe('jane-form')
        ->and(Crypt::decryptString($row->secret))->toBe('hashed-secret');
});

it('stamps whether the type served challenge when stored', function () {
    credentials()->store(User::factory()->create(), new FormType(surfaces: ['challenge']), identifier: null, secret: 'hashed-secret');

    expect(DB::table('user_credentials')->value('served_challenge'))->toEqual(1);
});

it('stamps whether the type proves two factors on its own when stored', function (bool $multipleFactors) {
    credentials()->store(User::factory()->create(), new FormType(multipleFactors: $multipleFactors), identifier: null, secret: 'hashed-secret');

    expect(DB::table('user_credentials')->value('proves_multiple_factors'))->toEqual($multipleFactors);
})->with(['one factor' => false, 'two factors' => true]);

it('stores a credential without an identifier', function () {
    credentials()->store(User::factory()->create(), new FormType, identifier: null, secret: 'hashed-secret');

    expect(DB::table('user_credentials')->sole())
        ->identifier->toBeNull()
        ->identifier_hash->toBeNull();
});

it('lists the account\'s usable credentials of a type, decrypted', function () {
    [$jane, $john] = User::factory()->count(2)->create();
    $credentials = credentials();
    $id = $credentials->store($jane, new FormType, identifier: 'jane-form', secret: 'jane-secret', label: 'Mine');
    $credentials->store($jane, new FormType(name: 'other'), identifier: null, secret: 'other-type');
    $credentials->store($john, new FormType, identifier: 'john-form', secret: 'john-secret');
    $disabled = $credentials->store($jane, new FormType, identifier: 'cloned', secret: 'disabled');
    DB::table('user_credentials')->where('id', $disabled)->update(['disabled_at' => now()]);

    expect($credentials->ofType($jane->getKey(), 'form'))
        ->toEqual([new StoredCredential($id, identifier: 'jane-form', secret: 'jane-secret', label: 'Mine')]);
});

it('finds the owner of a usable credential of a type', function () {
    $user = User::factory()->create();
    $id = credentials()->store($user, new FormType, identifier: null, secret: 'secret');

    expect(credentials()->ownerOf($id, 'form'))->toEqual($user->getKey());
});

it('finds no owner for a credential of another type, a disabled one or a missing one', function () {
    $user = User::factory()->create();
    $id = credentials()->store($user, new FormType, identifier: null, secret: 'secret');
    $disabled = credentials()->store($user, new FormType, identifier: null, secret: 'secret');
    DB::table('user_credentials')->where('id', $disabled)->update(['disabled_at' => now()]);

    expect(credentials()->ownerOf($id, 'other'))->toBeNull()
        ->and(credentials()->ownerOf($disabled, 'form'))->toBeNull()
        ->and(credentials()->ownerOf($id + 100, 'form'))->toBeNull();
});

it('lists the types the account holds a usable credential of, once each', function () {
    [$jane, $john] = User::factory()->count(2)->create();
    $credentials = credentials();
    $credentials->store($jane, new FormType, identifier: null, secret: 'one');
    $credentials->store($jane, new FormType, identifier: null, secret: 'two');
    $credentials->store($john, new FormType(name: 'other'), identifier: null, secret: 'john');
    $disabled = $credentials->store($jane, new FormType(name: 'cloned'), identifier: null, secret: 'disabled');
    DB::table('user_credentials')->where('id', $disabled)->update(['disabled_at' => now()]);

    expect($credentials->typesOf($jane->getKey()))->toBe(['form']);
});

it('tells whether the account holds a usable credential of another type than the first factor\'s, stamped as serving the challenge', function () {
    [$held, $firstFactorOnly, $disabled, $other] = User::factory()->count(4)->create();
    $credentials = credentials();
    $credentials->store($held, new FormType(name: 'unlisted', surfaces: ['challenge']), identifier: null, secret: 'secret');
    $credentials->store($firstFactorOnly, new FormType, identifier: null, secret: 'secret');
    $id = $credentials->store($disabled, new FormType(surfaces: ['challenge']), identifier: null, secret: 'secret');
    DB::table('user_credentials')->where('id', $id)->update(['disabled_at' => now()]);
    $credentials->store($other, new FormType(surfaces: ['challenge']), identifier: null, secret: 'secret');

    expect($credentials->holdsSecondFactor($held->getKey(), 'form'))->toBeTrue()
        ->and($credentials->holdsSecondFactor($held->getKey(), 'unlisted'))->toBeFalse()
        ->and($credentials->holdsSecondFactor($firstFactorOnly->getKey(), 'other'))->toBeFalse()
        ->and($credentials->holdsSecondFactor($disabled->getKey(), 'other'))->toBeFalse();
});
