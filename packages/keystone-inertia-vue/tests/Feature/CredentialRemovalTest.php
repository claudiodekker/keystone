<?php

use ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions\CredentialRemovalAssertions;
use ClaudioDekker\Keystone\InertiaVue\Tests\StubsTestCase;
use ClaudioDekker\Keystone\Password\AppTests\Support\PasswordTypeSupport;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

pest()->extend(StubsTestCase::class)->use(CredentialRemovalAssertions::class);

beforeEach(function () {
    $this->withoutMandates();
    config(['keystone.methods' => ['password', 'totp']]);
});

it('renders the confirm step with the page value\'s fields', function () {
    $account = $this->signInAccount(new PasswordTypeSupport);
    $id = DB::table('user_credentials')->insertGetId(['user_id' => $account->getKey(), 'type' => 'uninstalled', 'label' => 'Old key']);

    $response = $this->get(route('security.credentials.remove', ['credential' => $id]));

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('settings/CredentialRemoval')
        ->where('id', $id)
        ->where('type', 'uninstalled')
        ->where('label', 'Old key')
        ->where('listed', false));
});

it('encrypts the confirm step in the browser\'s history', function () {
    $account = $this->signInAccount(new PasswordTypeSupport);
    $id = DB::table('user_credentials')->insertGetId(['user_id' => $account->getKey(), 'type' => 'totp']);

    $response = $this->get(route('security.credentials.remove', ['credential' => $id]));

    expect($response->inertiaPage())->toHaveKey('encryptHistory', true);
});

it('shows the removal\'s status on the security page', function () {
    $account = $this->signInAccount(new PasswordTypeSupport);
    $id = DB::table('user_credentials')->insertGetId(['user_id' => $account->getKey(), 'type' => 'totp']);

    $this->assertCredentialRemoved($this->delete(route('security.credentials.remove.submit', ['credential' => $id])));

    $this->get(route('security'))->assertInertia(fn (AssertableInertia $page) => $page
        ->component('settings/Security')
        ->where('status', __('keystone::messages.status.credential-removed')));
});

it('shows the status of a credential the account doesn\'t hold on the security page', function () {
    $this->signInAccount(new PasswordTypeSupport);

    $this->assertCredentialNotFound($this->get(route('security.credentials.remove', ['credential' => 999])));

    $this->get(route('security'))->assertInertia(fn (AssertableInertia $page) => $page
        ->component('settings/Security')
        ->where('status', __('keystone::messages.status.credential-not-found')));
});

it('sends a refused removal back to the confirm step with the message', function () {
    $account = $this->signInAccount(new PasswordTypeSupport);
    $id = DB::table('user_credentials')->where('user_id', $account->getKey())->value('id');

    $response = $this->delete(route('security.credentials.remove.submit', ['credential' => $id]));

    $this->assertRemovalRefused($response, $id, __('keystone::messages.last_sign_in_credential'));
    $this->get(route('security.credentials.remove', ['credential' => $id]))->assertInertia(fn (AssertableInertia $page) => $page
        ->component('settings/CredentialRemoval')
        ->where('errors.credential', __('keystone::messages.last_sign_in_credential')));
});
