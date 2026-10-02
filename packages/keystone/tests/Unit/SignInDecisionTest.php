<?php

use Carbon\CarbonImmutable;
use ClaudioDekker\Keystone\Demand;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\PendingOrigin;
use ClaudioDekker\Keystone\PendingSignIn;
use ClaudioDekker\Keystone\PendingStage;
use ClaudioDekker\Keystone\SignInDecision;
use ClaudioDekker\Keystone\Tests\Fixtures\FormType;
use ClaudioDekker\Keystone\Tests\Fixtures\User;
use ClaudioDekker\Keystone\Tests\Fixtures\UserWithArchivedAt;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function readAccount(User $user, string $model = User::class): User
{
    return $model::query()->withoutGlobalScopes()->findOrFail($user->getKey());
}

function accountHolding(bool $secondFactor = false, bool $recoveryCodes = false): User
{
    $user = User::factory()->create();

    if ($secondFactor) {
        DB::table('user_credentials')->insert(['user_id' => $user->getKey(), 'type' => 'code', 'created_at' => now(), 'updated_at' => now()]);
    }

    if ($recoveryCodes) {
        DB::table('user_recovery_codes')->insert(['user_id' => $user->getKey(), 'code_hash' => hash('sha256', 'AAAAA-AAAAA'), 'created_at' => now()]);
    }

    return readAccount($user);
}

function pendingFor(User $account, ?string $firstFactor = 'form', bool $secondFactorPassed = false): PendingSignIn
{
    return new PendingSignIn($account, $firstFactor, PendingOrigin::LOGIN, PendingStage::CHALLENGE, '/', CarbonImmutable::now(), $secondFactorPassed);
}

it('signs in an active account', function () {
    expect((new SignInDecision)->demand(readAccount(User::factory()->create()), new FormType))->toBe(Demand::SIGN_IN);
});

it('refuses a disabled or suspended account', function (string $column) {
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->getKey())->update([$column => now()]);

    expect((new SignInDecision)->demand(readAccount($user), new FormType))->toBe(Demand::REFUSE);
})->with(['deleted_at', 'invalidated_at', 'suspended_at']);

it('refuses an account soft deleted under its own column name', function () {
    Schema::table('users', fn (Blueprint $table) => $table->timestamp('archived_at')->nullable());
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->getKey())->update(['archived_at' => now()]);

    expect((new SignInDecision)->demand(readAccount($user, UserWithArchivedAt::class), new FormType))->toBe(Demand::REFUSE);
});

it('decides on the mandates as the account\'s credentials and codes stand', function (bool $secondFactor, bool $recoveryCodes, bool $holdsSecondFactor, bool $holdsCodes, Demand $demand) {
    config(['keystone.require_second_factor' => $secondFactor, 'keystone.require_recovery_codes' => $recoveryCodes]);

    expect((new SignInDecision)->demand(accountHolding($holdsSecondFactor, $holdsCodes), new FormType))->toBe($demand);
})->with([
    'nothing required, nothing held' => [false, false, false, false, Demand::SIGN_IN],
    'second factor required, none held' => [true, false, false, false, Demand::ENROLLMENT],
    'second factor required and held' => [true, false, true, false, Demand::CHALLENGE],
    'codes required, none held' => [false, true, false, false, Demand::ENROLLMENT],
    'codes required and held' => [false, true, false, true, Demand::SIGN_IN],
    'both required, second factor held' => [true, true, true, false, Demand::CHALLENGE],
    'both required, both held' => [true, true, true, true, Demand::CHALLENGE],
]);

it('counts neither a disabled second factor nor a spent set of recovery codes as held', function () {
    config(['keystone.require_second_factor' => true, 'keystone.require_recovery_codes' => true]);
    $account = accountHolding(secondFactor: true, recoveryCodes: true);
    DB::table('user_credentials')->update(['disabled_at' => now()]);
    DB::table('user_recovery_codes')->delete();

    expect((new SignInDecision)->owesSecondFactor($account))->toBeTrue()
        ->and((new SignInDecision)->owesRecoveryCodes($account))->toBeTrue();
});

it('reads what the account holds as it stands now, not as it was loaded', function () {
    config(['keystone.require_second_factor' => true, 'keystone.require_recovery_codes' => true]);
    $account = accountHolding();
    $owedBefore = (new SignInDecision)->owesEnrollment($account);

    DB::table('user_credentials')->insert(['user_id' => $account->getKey(), 'type' => 'code', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('user_recovery_codes')->insert(['user_id' => $account->getKey(), 'code_hash' => hash('sha256', 'AAAAA-AAAAA'), 'created_at' => now()]);

    expect($owedBefore)->toBeTrue()
        ->and((new SignInDecision)->owesEnrollment($account))->toBeFalse();
});

it('reads nothing from the database while neither mandate is on', function () {
    config(['keystone.require_second_factor' => false, 'keystone.require_recovery_codes' => false]);
    $account = accountHolding();

    DB::enableQueryLog();
    $owes = (new SignInDecision)->owesEnrollment($account);

    expect($owes)->toBeFalse()
        ->and(DB::getQueryLog())->toBe([]);
});

it('owes no second factor after a proof that counts as two factors, while still owing recovery codes', function (bool $recoveryCodes, Demand $demand) {
    config(['keystone.require_second_factor' => true, 'keystone.require_recovery_codes' => $recoveryCodes]);

    expect((new SignInDecision)->demand(accountHolding(), new FormType(name: 'key', multipleFactors: true)))->toBe($demand);
})->with([
    'codes optional' => [false, Demand::SIGN_IN],
    'codes required' => [true, Demand::ENROLLMENT],
]);

it('never owes recovery codes while they are optional', function () {
    config(['keystone.require_second_factor' => false, 'keystone.require_recovery_codes' => false]);

    expect((new SignInDecision)->owesEnrollment(accountHolding()))->toBeFalse();
});

it('reads what a pending sign-in still owes from its account as it stands', function (bool $holdsSecondFactor, bool $holdsCodes, bool $secondFactorPassed, Demand $demand) {
    config(['keystone.require_second_factor' => true, 'keystone.require_recovery_codes' => true]);

    expect((new SignInDecision)->next(pendingFor(accountHolding($holdsSecondFactor, $holdsCodes), secondFactorPassed: $secondFactorPassed)))->toBe($demand);
})->with([
    'a second factor added elsewhere before the challenge' => [true, true, false, Demand::CHALLENGE],
    'the challenge passed, codes owed' => [true, false, true, Demand::ENROLLMENT],
    'the challenge passed, nothing owed' => [true, true, true, Demand::SIGN_IN],
    'no second factor yet' => [false, false, false, Demand::ENROLLMENT],
]);

it('treats a demoted session\'s unknown first factor as one factor', function () {
    config(['keystone.require_second_factor' => true, 'keystone.require_recovery_codes' => false]);

    expect((new SignInDecision)->pendingOwesSecondFactor(pendingFor(accountHolding(), firstFactor: null, secondFactorPassed: true)))->toBeTrue();
});

it('refuses a pending sign-in whose account was barred since', function () {
    $account = accountHolding();
    DB::table('users')->where('id', $account->getKey())->update(['suspended_at' => now()]);

    expect((new SignInDecision)->next(pendingFor(readAccount($account))))->toBe(Demand::REFUSE);
});

it('challenges a single-factor proof of an account whose other credential proves two factors on its own', function () {
    app(CredentialTypes::class)->register(new FormType(name: 'passkey', surfaces: ['sign-in', 'challenge'], multipleFactors: true));
    $user = User::factory()->create();
    DB::table('user_credentials')->insert(['user_id' => $user->getKey(), 'type' => 'passkey', 'created_at' => now(), 'updated_at' => now()]);

    expect((new SignInDecision)->demand(readAccount($user), new FormType))->toBe(Demand::CHALLENGE);
});

it('offers for enrollment the types that answer a challenge', function () {
    $types = new CredentialTypes;
    $types->register(new FormType(name: 'totp', surfaces: ['challenge', 'enrollment']));
    $types->register(new FormType(name: 'key', surfaces: ['sign-in', 'challenge', 'enrollment'], multipleFactors: true));
    $types->register(new FormType(name: 'phrase', surfaces: ['sign-in', 'enrollment'], multipleFactors: true));
    $types->register(new FormType(name: 'password', surfaces: ['sign-in', 'enrollment']));
    $types->register(new FormType(name: 'sms', surfaces: ['challenge']));

    $offer = (new SignInDecision)->enrollmentOffer($types);

    expect(array_map(fn ($type) => $type->name(), $offer))->toBe(['totp', 'key'])
        ->and((new SignInDecision)->mandateSatisfiable($types))->toBeTrue();
});

it('finds the mandate unsatisfiable when no listed type can be enrolled as a second factor', function () {
    $types = new CredentialTypes;
    $types->register(new FormType(name: 'password', surfaces: ['sign-in', 'enrollment']));

    expect((new SignInDecision)->mandateSatisfiable($types))->toBeFalse();
});
