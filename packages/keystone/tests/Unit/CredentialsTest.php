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
