<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('adds keystone columns to the users table', function () {
    expect(Schema::hasColumns('users', [
        'credential_epoch',
        'credential_epoch_moved_at',
        'has_second_factor',
        'has_recovery_codes',
        'deleted_at',
        'invalidated_at',
        'suspended_at',
    ]))->toBeTrue();
});

it('starts a new account at epoch zero, owing no state flags', function () {
    DB::table('users')->insert(['name' => 'Taylor']);

    expect(DB::table('users')->first(['credential_epoch', 'has_second_factor', 'has_recovery_codes']))
        ->credential_epoch->toEqual(0)
        ->has_second_factor->toBeFalsy()
        ->has_recovery_codes->toBeFalsy();
});

it('makes the app email, password and remember token columns nullable without dropping them', function () {
    DB::table('users')->insert(['name' => 'Taylor']);

    expect(DB::table('users')->first(['email', 'password', 'remember_token']))
        ->email->toBeNull()
        ->password->toBeNull()
        ->remember_token->toBeNull();
});
