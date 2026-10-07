<?php

use ClaudioDekker\Keystone\AcceptedProof;
use ClaudioDekker\Keystone\Demand;
use ClaudioDekker\Keystone\Entry;
use ClaudioDekker\Keystone\Flow;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\RecoveryCodeType;
use ClaudioDekker\Keystone\PendingStage;
use ClaudioDekker\Keystone\RateLimiter;
use ClaudioDekker\Keystone\RememberMe;
use ClaudioDekker\Keystone\Subnet;
use ClaudioDekker\Keystone\SudoPass;
use ClaudioDekker\Keystone\SudoResult;
use ClaudioDekker\Keystone\TakenAttempt;
use ClaudioDekker\Keystone\Tests\Fixtures\FormType;
use ClaudioDekker\Keystone\Tests\Fixtures\User;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config(['cache.limiter' => 'array', 'keystone.require_second_factor' => false, 'keystone.require_recovery_codes' => false]);
    app()->forgetInstance(CacheRateLimiter::class);
});

function stepAccount(bool $secondFactor = false, bool $recoveryCodes = false, bool $suspended = false): User
{
    $user = User::factory()->create();

    if ($secondFactor) {
        DB::table('user_credentials')->insert(['user_id' => $user->getKey(), 'type' => 'code', 'created_at' => now(), 'updated_at' => now()]);
    }

    if ($recoveryCodes) {
        DB::table('user_recovery_codes')->insert(['user_id' => $user->getKey(), 'code_hash' => hash('sha256', 'AAAAA-AAAAA'), 'created_at' => now()]);
    }

    if ($suspended) {
        suspendStepAccount($user);
    }

    return User::query()->withoutGlobalScopes()->findOrFail($user->getKey());
}

function suspendStepAccount(User $account): void
{
    DB::table('users')->where('id', $account->getKey())->update(['suspended_at' => now()]);
}

function stepLimiter(): RateLimiter
{
    return new RateLimiter(request(), Keystone::guard());
}

function signInStep(User $account): array
{
    $taken = stepLimiter()->takeFailedAttempt(Flow::SIGN_IN, new FormType, $account, 'someone@example.com');
    $passed = (new Entry(Keystone::guard()))->afterFirstFactor($account, new FormType, '/home', RememberMe::NOT_ASKED);

    return [$passed, $account, Flow::SIGN_IN, 'form', $taken];
}

function challengeStep(User $account, CredentialType $type, bool $suspend = false): array
{
    Keystone::guard()->hold($account, firstFactor: 'form', stage: PendingStage::CHALLENGE, intendedUrl: '/home');
    $pending = Keystone::guard()->pending();
    $taken = stepLimiter()->takeFailedAttempt(Flow::CHALLENGE, $type, $account, identifier: '');

    if ($suspend) {
        suspendStepAccount($account);
    }

    return [(new Entry(Keystone::guard()))->afterChallenge($pending), $account, Flow::CHALLENGE, $type->name(), $taken];
}

function enrollmentStep(User $account, bool $suspend = false): array
{
    Keystone::guard()->hold($account, firstFactor: 'form', stage: PendingStage::ENROLLMENT, intendedUrl: '/home');

    if ($suspend) {
        suspendStepAccount($account);
    }

    return [(new Entry(Keystone::guard()))->afterSecondFactorEnrollment(), $account, Flow::ENROLLMENT, 'code', null];
}

function recoveryCodeSetupStep(User $account): array
{
    Keystone::guard()->hold($account, firstFactor: 'form', stage: PendingStage::ENROLLMENT, intendedUrl: '/home');

    return [(new Entry(Keystone::guard()))->afterRecoveryCodeSetup(), $account, Flow::ENROLLMENT, 'recovery-code', null];
}

function sudoFirstStep(User $account): array
{
    Keystone::guard()->setUser($account);
    Keystone::guard()->beginSudo('/settings');
    $taken = stepLimiter()->takeFailedAttempt(Flow::SUDO, new FormType, $account, identifier: '');

    return [(new SudoPass(Keystone::guard()))->passFirstStep(Keystone::guard()->sudoInProgress(), 'form'), $account, Flow::SUDO, 'form', $taken];
}

function sudoChallengeStep(User $account, CredentialType $type): array
{
    Keystone::guard()->setUser($account);
    Keystone::guard()->beginSudo('/settings');
    Keystone::guard()->passSudoFirstFactor(Keystone::guard()->sudoInProgress(), 'form');
    $taken = stepLimiter()->takeFailedAttempt(Flow::SUDO, $type, $account, identifier: '');

    return [(new SudoPass(Keystone::guard()))->grant(Subnet::of('203.0.113.5')), $account, Flow::SUDO, $type->name(), $taken];
}

function sessionPhase(): string
{
    $guard = Keystone::guard();
    $progress = $guard->sudoInProgress();

    return match (true) {
        $progress !== null => $progress->firstFactor === null ? 'owes the first sudo step' : 'owes the sudo challenge',
        $guard->check() => $guard->sudoGrant() === null ? 'signed in' : 'signed in with sudo',
        $guard->isPendingAt(PendingStage::CHALLENGE) => 'held at the challenge',
        $guard->isPendingAt(PendingStage::ENROLLMENT) => 'held at enrollment',
        default => 'guest',
    };
}

function stepEvents(User $account, Flow $flow, string $type): array
{
    $rows = DB::table('user_security_events')
        ->where(['user_id' => $account->getKey(), 'flow' => $flow->value, 'credential_type' => $type])
        ->orderBy('id')
        ->get();

    return $rows->map(fn (object $row) => [$row->type, $row->reason, $row->known_device === null ? null : (bool) $row->known_device])->all();
}

function isGivenBack(?TakenAttempt $taken): ?bool
{
    if ($taken === null) {
        return null;
    }

    return array_sum(array_map(fn ($count) => app(CacheRateLimiter::class)->attempts($count->key), $taken->counts)) === 0;
}

it('concludes each passed step with its session, its event and its taken attempt', function (Closure $step, mixed $outcome, string $session, array $events, ?bool $givenBack) {
    [$passed, $account, $flow, $type, $taken] = $step();

    $concluded = (new AcceptedProof(limiter: stepLimiter()))->conclude($passed, $account, $flow, $type, taken: $taken);

    expect($concluded)->toBe($outcome)
        ->and(sessionPhase())->toBe($session)
        ->and(stepEvents($account, $flow, $type))->toBe($events)
        ->and(isGivenBack($taken))->toBe($givenBack);
})->with([
    'a sign-in that owes nothing more' => [
        fn () => signInStep(stepAccount()),
        Demand::SIGN_IN, 'signed in with sudo', [['signed_in', null, false]], true,
    ],
    'a sign-in that owes the challenge' => [
        fn () => signInStep(stepAccount(secondFactor: true)),
        Demand::CHALLENGE, 'held at the challenge', [['sign_in.held', 'keystone.challenge', null]], true,
    ],
    'a sign-in that owes enrollment' => [
        function () {
            config(['keystone.require_second_factor' => true]);

            return signInStep(stepAccount());
        },
        Demand::ENROLLMENT, 'held at enrollment', [['sign_in.held', 'keystone.enrollment', null]], true,
    ],
    'a sign-in by a barred account' => [
        fn () => signInStep(stepAccount(suspended: true)),
        null, 'guest', [['proof.rejected', 'keystone.barred', null]], false,
    ],
    'a challenge' => [
        fn () => challengeStep(stepAccount(secondFactor: true), new FormType('code')),
        Demand::SIGN_IN, 'signed in with sudo', [['signed_in', null, false]], true,
    ],
    'a challenge that leaves enrollment owed' => [
        function () {
            config(['keystone.require_recovery_codes' => true]);

            return challengeStep(stepAccount(secondFactor: true), new FormType('code'));
        },
        Demand::ENROLLMENT, 'held at enrollment', [['sign_in.held', 'keystone.enrollment', null]], true,
    ],
    'a challenge by recovery code' => [
        fn () => challengeStep(stepAccount(secondFactor: true, recoveryCodes: true), new RecoveryCodeType),
        Demand::SIGN_IN, 'signed in with sudo', [['signed_in', null, false]], true,
    ],
    'a challenge by an account barred since its hold' => [
        fn () => challengeStep(stepAccount(secondFactor: true), new FormType('code'), suspend: true),
        null, 'held at the challenge', [['proof.rejected', 'keystone.barred', null]], false,
    ],
    'an enrollment' => [
        fn () => enrollmentStep(stepAccount(secondFactor: true)),
        Demand::SIGN_IN, 'signed in with sudo', [['signed_in', null, false]], null,
    ],
    'an enrollment that leaves recovery codes owed' => [
        function () {
            config(['keystone.require_recovery_codes' => true]);

            return enrollmentStep(stepAccount(secondFactor: true));
        },
        Demand::ENROLLMENT, 'held at enrollment', [], null,
    ],
    'an enrollment by an account barred since its hold' => [
        fn () => enrollmentStep(stepAccount(secondFactor: true), suspend: true),
        null, 'held at enrollment', [['proof.rejected', 'keystone.barred', null]], null,
    ],
    'a recovery-code setup' => [
        fn () => recoveryCodeSetupStep(stepAccount(recoveryCodes: true)),
        Demand::SIGN_IN, 'signed in with sudo', [['signed_in', null, false]], null,
    ],
    'a recovery-code setup that leaves a second factor owed' => [
        function () {
            config(['keystone.require_second_factor' => true]);

            return recoveryCodeSetupStep(stepAccount(recoveryCodes: true));
        },
        Demand::ENROLLMENT, 'held at enrollment', [], null,
    ],
    'a recovery-code setup by an account that now owes the challenge' => [
        fn () => recoveryCodeSetupStep(stepAccount(secondFactor: true, recoveryCodes: true)),
        Demand::CHALLENGE, 'guest', [], null,
    ],
    'a first sudo step that owes the challenge' => [
        fn () => sudoFirstStep(stepAccount(secondFactor: true)),
        SudoResult::CHALLENGE_OWED, 'owes the sudo challenge', [], true,
    ],
    'a sudo challenge' => [
        fn () => sudoChallengeStep(stepAccount(secondFactor: true), new FormType('code')),
        SudoResult::GRANTED, 'signed in with sudo', [['sudo.granted', null, null]], true,
    ],
    'a sudo step by recovery code' => [
        fn () => sudoChallengeStep(stepAccount(secondFactor: true, recoveryCodes: true), new RecoveryCodeType),
        SudoResult::GRANTED, 'signed in with sudo', [['sudo.granted', null, null]], true,
    ],
]);
