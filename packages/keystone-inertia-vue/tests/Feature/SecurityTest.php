<?php

use ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions\SecurityAssertions;
use ClaudioDekker\Keystone\InertiaVue\Tests\StubsTestCase;
use ClaudioDekker\Keystone\Password\AppTests\Support\PasswordTypeSupport;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

pest()->extend(StubsTestCase::class)->use(SecurityAssertions::class);

beforeEach(function () {
    $this->withoutMandates();
    config(['keystone.methods' => ['password', 'totp']]);
});

it('renders the security page with the page value\'s fields', function () {
    $this->freezeSecond();
    $account = $this->signInAccount(new PasswordTypeSupport);
    $password = DB::table('user_credentials')->where('user_id', $account->getKey())->value('id');
    $leftover = DB::table('user_credentials')->insertGetId(['user_id' => $account->getKey(), 'type' => 'uninstalled', 'label' => 'Old key', 'created_at' => now()]);

    $response = $this->get(route('security'));

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('settings/Security')
        ->where('types', [
            ['type' => 'password', 'enrollable' => true, 'credentials' => [['id' => $password, 'label' => null, 'addedAt' => now()->toIso8601String(), 'lastUsedAt' => now()->toIso8601String(), 'disabled' => false]]],
            ['type' => 'totp', 'enrollable' => true, 'credentials' => []],
        ])
        ->where('leftovers', [['id' => $leftover, 'type' => 'uninstalled', 'label' => 'Old key', 'addedAt' => now()->toIso8601String(), 'lastUsedAt' => null, 'disabled' => false]])
        ->where('recoveryCodes', 0)
        ->where('recoveryCodesLow', true)
        ->where('sudoEndsAt', now()->addMinutes(15)->toIso8601String())
        ->where('status', null));
});

it('shows the status of an ended sudo', function () {
    $this->signInAccount(new PasswordTypeSupport);
    $this->delete(route('sudo.end'));

    $response = $this->get(route('security'));

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('settings/Security')
        ->where('sudoEndsAt', null)
        ->where('status', __('keystone::messages.status.sudo-revoked')));
});

it('encrypts the security page in the browser\'s history', function () {
    $this->signInAccount(new PasswordTypeSupport);

    $response = $this->get(route('security'));

    expect($response->inertiaPage())->toHaveKey('encryptHistory', true);
});

it('sends a guest to the sign-in page', function () {
    $this->assertGuestSentAwayFromSecurity($this->get(route('security')));
});

it('sends /.well-known/change-password to the security page for a guest and a signed-in user', function () {
    $this->get('/.well-known/change-password')->assertRedirect(route('security'));
    $this->signInAccount(new PasswordTypeSupport);

    $response = $this->get('/.well-known/change-password');

    $response->assertRedirect(route('security'));
    expect(route('well-known.change-password', absolute: false))->toBe('/.well-known/change-password');
});
