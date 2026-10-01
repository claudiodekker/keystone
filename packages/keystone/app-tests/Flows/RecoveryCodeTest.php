<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\AppTests\Assertions\ChallengeAssertions;
use ClaudioDekker\Keystone\AppTests\Assertions\SignOutAssertions;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Surface;

pest()->extend(AppTestCase::class)->use(
    AppTestCase::assertions(ChallengeAssertions::class),
    AppTestCase::assertions(SignOutAssertions::class),
);

beforeEach(function () {
    $this->support = $this->supportsFor(Surface::CHALLENGE)[0];
});

it('offers recovery codes after the account\'s second factor while it holds one', function () {
    $account = $this->createChallengedAccount($this->support);
    $this->arrangeRecoveryCodes($account);
    $this->passFirstFactor();

    $response = $this->get(route('login.challenge'));

    $this->assertChallengePage($response, [$this->support->type(), CredentialTypes::RECOVERY_CODE]);
});

it('offers recovery codes when no listed type answers the account\'s second factor', function () {
    $account = $this->createChallengedAccount($this->support);
    $this->arrangeRecoveryCodes($account);
    $this->passFirstFactor();
    config(['keystone.methods' => [$this->supportsFor(Surface::SIGN_IN)[0]->type()]]);

    $response = $this->get(route('login.challenge'));

    $this->assertChallengePage($response, [CredentialTypes::RECOVERY_CODE]);
});

it('completes the sign-in with a recovery code, spending it', function () {
    $account = $this->createChallengedAccount($this->support);
    $codes = $this->arrangeRecoveryCodes($account);
    $this->passFirstFactor();

    $response = $this->post(route('login.challenge.submit', ['type' => CredentialTypes::RECOVERY_CODE]), ['code' => $codes[0]]);

    $this->assertChallengePassed($response, '/');
    $this->assertAuthenticatedAs($account);
    $this->assertDatabaseCount('user_recovery_codes', count($codes) - 1);
});

it('refuses a recovery code that was already spent', function () {
    $account = $this->createChallengedAccount($this->support);
    $codes = $this->arrangeRecoveryCodes($account);
    $this->passFirstFactor();
    $this->post(route('login.challenge.submit', ['type' => CredentialTypes::RECOVERY_CODE]), ['code' => $codes[0]]);
    $this->assertSignedOut($this->post(route('logout')));
    $this->passFirstFactor();

    $response = $this->post(route('login.challenge.submit', ['type' => CredentialTypes::RECOVERY_CODE]), ['code' => $codes[0]]);

    $this->assertChallengeRefused($response, CredentialTypes::RECOVERY_CODE);
    $this->assertGuest();
});

it('keeps the account\'s last recovery code for account recovery', function () {
    $account = $this->createChallengedAccount($this->support);
    [$last] = $this->arrangeRecoveryCodes($account, count: 1);
    $this->passFirstFactor();

    $response = $this->post(route('login.challenge.submit', ['type' => CredentialTypes::RECOVERY_CODE]), ['code' => $last]);

    $this->assertLastRecoveryCodeKept($response);
    $this->assertGuest();
    $this->assertDatabaseCount('user_recovery_codes', 1);
});
