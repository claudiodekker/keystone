<?php

use ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions\RecoveryCodeRegenerationAssertions;
use ClaudioDekker\Keystone\InertiaVue\Tests\StubsTestCase;
use ClaudioDekker\Keystone\Password\AppTests\Support\PasswordTypeSupport;
use ClaudioDekker\Keystone\RecoveryCodes;
use Inertia\Testing\AssertableInertia;

pest()->extend(StubsTestCase::class)->use(RecoveryCodeRegenerationAssertions::class);

beforeEach(function () {
    $this->freezeSecond();
    $this->withoutMandates();
    config(['keystone.methods' => ['password', 'totp']]);
});

it('renders the staged set with the page value\'s fields', function () {
    $account = $this->signInAccount(new PasswordTypeSupport);
    $this->arrangeRecoveryCodes($account);

    $response = $this->get(route('security.recovery-codes.regenerate'));

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('settings/RegenerateRecoveryCodes')
        ->where('codes', $this->regenerationCodes())
        ->where('replaces', true)
        ->where('status', null));
});

it('encrypts the staged set in the browser\'s history', function () {
    $this->signInAccount(new PasswordTypeSupport);

    $response = $this->get(route('security.recovery-codes.regenerate'));

    expect($response->inertiaPage())->toHaveKey('encryptHistory', true);
});

it('shows the regenerated status on the security page', function () {
    $this->signInAccount(new PasswordTypeSupport);
    $this->get(route('security.recovery-codes.regenerate'));

    $response = $this->post(route('security.recovery-codes.regenerate.confirm'), ['code' => $this->regenerationCodes()[0]]);

    $this->assertRecoveryCodesRegenerated($response);
    $this->get(route('security'))->assertInertia(fn (AssertableInertia $page) => $page
        ->component('settings/Security')
        ->where('status', __('keystone::messages.status.recovery-codes-regenerated'))
        ->where('recoveryCodes', RecoveryCodes::SET_SIZE));
});

it('sends a wrong code back to the staged set with the message on the code field', function () {
    $this->signInAccount(new PasswordTypeSupport);
    $this->get(route('security.recovery-codes.regenerate'));
    $codes = $this->regenerationCodes();

    $response = $this->post(route('security.recovery-codes.regenerate.confirm'), ['code' => 'wrong']);

    $this->assertRecoveryCodeRegenerationRefused($response);
    $this->assertRecoveryCodeRegenerationPage($this->get(route('security.recovery-codes.regenerate')), $codes);
});

it('sends a code typed with nothing staged back for a fresh set, which says so', function () {
    $this->signInAccount(new PasswordTypeSupport);

    $response = $this->post(route('security.recovery-codes.regenerate.confirm'), ['code' => 'anything']);

    $this->assertRecoveryCodeRegenerationExpired($response);
    $this->get(route('security.recovery-codes.regenerate'))->assertInertia(fn (AssertableInertia $page) => $page
        ->component('settings/RegenerateRecoveryCodes')
        ->where('status', __('keystone::messages.status.recovery-codes-expired')));
});

it('sends a discarded set to the security page', function () {
    $this->signInAccount(new PasswordTypeSupport);
    $this->get(route('security.recovery-codes.regenerate'));

    $response = $this->delete(route('security.recovery-codes.regenerate.cancel'));

    $this->assertRecoveryCodeRegenerationCancelled($response);
    expect($this->regenerationCodes())->toBe([]);
});
