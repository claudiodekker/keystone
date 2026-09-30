<?php

use ClaudioDekker\Keystone\Actions\EndSessions;
use ClaudioDekker\Keystone\Actions\SuspendAccount;
use ClaudioDekker\Keystone\Actions\UnsuspendAccount;
use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\AppTests\Support\CredentialTypeSupport;
use ClaudioDekker\Keystone\KeystoneUser;
use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;

pest()->extend(AppTestCase::class);

/**
 * Submit a valid proof for the account's address on the sign-in page.
 */
function signInAfterAccountWrite(AppTestCase $test, CredentialTypeSupport $support): void
{
    $test->post(route('login.submit', ['type' => $support->type()]), ['identifier' => 'jane@example.com', ...$support->validProof(Surface::SIGN_IN)]);
}

/**
 * Get the account's credential epoch as its row holds it.
 */
function epochAfterAccountWrite(Model&KeystoneUser $account): int
{
    return (int) $account->newQueryWithoutScopes()->toBase()->where($account->getKeyName(), $account->getKey())->value('credential_epoch');
}

beforeEach(function () {
    $this->support = $this->supportsFor(Surface::SIGN_IN)[0];
    Route::middleware(['web', 'auth'])->get('keystone-app-tests/signed-in-only', fn () => 'Signed in.');
});

it('ends the account\'s sessions through the app\'s EndSessions', function () {
    $account = $this->signInAccount($this->support);
    // An operator acts from a session of their own, so the account's session isn't the mover's.
    session()->flush();

    app(EndSessions::class)->handle($account, operator: 'jane');

    $this->get('keystone-app-tests/signed-in-only');
    $this->assertGuest();
    expect(epochAfterAccountWrite($account))->toBe(1);
    $this->assertDatabaseHas('user_security_events', ['type' => 'sessions.terminated', 'user_id' => $account->getKey(), 'operator' => 'jane']);
});

it('ends the account\'s sessions and bars it from signing in through the app\'s SuspendAccount', function () {
    $account = $this->signInAccount($this->support);
    // An operator acts from a session of their own, so the account's session isn't the mover's.
    session()->flush();

    app(SuspendAccount::class)->handle($account, operator: 'jane');

    $this->get('keystone-app-tests/signed-in-only');
    $this->assertGuest();
    expect(epochAfterAccountWrite($account))->toBe(1);
    signInAfterAccountWrite($this, $this->support);
    $this->assertGuest();
    $this->assertDatabaseHas('user_security_events', ['type' => 'account.suspended', 'user_id' => $account->getKey(), 'operator' => 'jane']);
});

it('lets the account sign in again through the app\'s UnsuspendAccount', function () {
    $account = $this->createAccount();
    $this->arrangeCredential($account, $this->support, Surface::SIGN_IN);
    $account->newQueryWithoutScopes()->toBase()->where($account->getKeyName(), $account->getKey())->update(['suspended_at' => now()]);

    app(UnsuspendAccount::class)->handle($account, operator: 'jane');

    signInAfterAccountWrite($this, $this->support);
    $this->assertAuthenticatedAs($account);
    $this->assertDatabaseHas('user_security_events', ['type' => 'account.unsuspended', 'user_id' => $account->getKey(), 'operator' => 'jane']);
});
