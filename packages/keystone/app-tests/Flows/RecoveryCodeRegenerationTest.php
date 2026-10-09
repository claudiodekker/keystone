<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\AppTests\Assertions\RecoveryCodeRegenerationAssertions;
use ClaudioDekker\Keystone\AppTests\Assertions\SudoAssertions;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\RecoveryCodes;

pest()->extend(AppTestCase::class)->use(
    AppTestCase::assertions(RecoveryCodeRegenerationAssertions::class),
    AppTestCase::assertions(SudoAssertions::class),
);

beforeEach(function () {
    $this->withoutMandates();
});

it('shows the same staged set on every visit, then replaces the account\'s codes when one is typed back', function () {
    $account = $this->signInAccount($this->supportsFor(Surface::SIGN_IN)[0]);
    $old = $this->arrangeRecoveryCodes($account);

    $first = $this->get(route('security.recovery-codes.regenerate'));
    $codes = $this->regenerationCodes();
    $reload = $this->get(route('security.recovery-codes.regenerate'));
    $response = $this->post(route('security.recovery-codes.regenerate.confirm'), ['code' => $codes[2]]);

    $this->assertRecoveryCodeRegenerationPage($first, $codes);
    $this->assertRecoveryCodeRegenerationPage($reload, $codes);
    $this->assertRecoveryCodesRegenerated($response);
    $stored = new RecoveryCodes($account);
    expect($stored->find($account->getKey(), $old[0]))->toBeNull()
        ->and($stored->find($account->getKey(), $codes[0]))->not->toBeNull();
    $this->assertDatabaseHas('user_security_events', ['type' => 'recovery_codes.generated', 'user_id' => $account->getKey(), 'flow' => 'settings']);
    $this->assertAuthenticatedAs($account);
});

it('refuses a code that isn\'t in the staged set, keeping the set and the account\'s codes', function () {
    $account = $this->signInAccount($this->supportsFor(Surface::SIGN_IN)[0]);
    $old = $this->arrangeRecoveryCodes($account);
    $this->get(route('security.recovery-codes.regenerate'));
    $codes = $this->regenerationCodes();

    $response = $this->post(route('security.recovery-codes.regenerate.confirm'), ['code' => 'not-one-of-them']);

    $this->assertRecoveryCodeRegenerationRefused($response);

    $reload = $this->get(route('security.recovery-codes.regenerate'));

    $this->assertRecoveryCodeRegenerationPage($reload, $codes);
    expect((new RecoveryCodes($account))->find($account->getKey(), $old[0]))->not->toBeNull();
    $this->assertDatabaseHas('user_security_events', ['type' => 'proof.rejected', 'user_id' => $account->getKey(), 'flow' => 'settings', 'reason' => 'recovery-code.mismatch']);
});

it('asks for sudo before it stages a set or stores one', function () {
    $account = $this->signInAccount($this->supportsFor(Surface::SIGN_IN)[0]);
    $this->delete(route('sudo.end'));

    $show = $this->get(route('security.recovery-codes.regenerate'));
    $confirm = $this->post(route('security.recovery-codes.regenerate.confirm'), ['code' => 'anything']);

    $this->assertSudoRequired($show);
    $this->assertSudoRequired($confirm);

    expect($this->regenerationCodes())->toBe([]);
    expect((new RecoveryCodes($account))->hasRemaining($account->getKey()))->toBeFalse();
});

it('sends a code typed with nothing staged back for a fresh set', function () {
    $account = $this->signInAccount($this->supportsFor(Surface::SIGN_IN)[0]);

    $response = $this->post(route('security.recovery-codes.regenerate.confirm'), ['code' => 'anything']);

    $this->assertRecoveryCodeRegenerationExpired($response);
    expect((new RecoveryCodes($account))->hasRemaining($account->getKey()))->toBeFalse();
});

it('discards the staged set without sudo, leaving the account\'s codes as they were', function () {
    $account = $this->signInAccount($this->supportsFor(Surface::SIGN_IN)[0]);
    $old = $this->arrangeRecoveryCodes($account);
    $this->get(route('security.recovery-codes.regenerate'));
    $this->delete(route('sudo.end'));

    $response = $this->delete(route('security.recovery-codes.regenerate.cancel'));

    $this->assertRecoveryCodeRegenerationCancelled($response);
    expect($this->regenerationCodes())->toBe([])
        ->and((new RecoveryCodes($account))->find($account->getKey(), $old[0]))->not->toBeNull();
});
