<?php

use ClaudioDekker\Keystone\Actions\EndSessions;
use ClaudioDekker\Keystone\Actions\SuspendAccount;
use ClaudioDekker\Keystone\Actions\UnsuspendAccount;
use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Support\Facades\Route;

pest()->extend(AppTestCase::class);

/**
 * Submit a valid proof for the account's address on the sign-in page.
 */
function submitProof(): void
{
    test()->post(route('login.submit', ['type' => test()->support->type()]), [
        'identifier' => 'jane@example.com',
        ...test()->support->validProof(Surface::SIGN_IN),
    ]);
}

beforeEach(function () {
    $this->support = $this->supportsFor(Surface::SIGN_IN)[0];
    Route::middleware(['web', 'auth'])->get('keystone-app-tests/signed-in-only', fn () => 'Signed in.');
});

it('ends the account\'s sessions through the app\'s EndSessions', function () {
    $account = $this->signInAccount($this->support);
    session()->flush();

    app(EndSessions::class)->handle($account, operator: 'jane');

    $this->get('keystone-app-tests/signed-in-only');
    $this->assertGuest();
    $this->assertDatabaseHas('user_security_events', ['type' => 'sessions.terminated', 'user_id' => $account->getKey(), 'operator' => 'jane']);
});

it('ends the account\'s sessions and bars it from signing in through the app\'s SuspendAccount', function () {
    $account = $this->signInAccount($this->support);
    session()->flush();

    app(SuspendAccount::class)->handle($account, operator: 'jane');

    $this->get('keystone-app-tests/signed-in-only');
    $this->assertGuest();
    submitProof();
    $this->assertGuest();
    $this->assertDatabaseHas('user_security_events', ['type' => 'account.suspended', 'user_id' => $account->getKey(), 'operator' => 'jane']);
});

it('lets the account sign in again through the app\'s UnsuspendAccount', function () {
    $account = $this->createAccount();
    $this->arrangeCredential($account, $this->support, Surface::SIGN_IN);
    app(SuspendAccount::class)->handle($account);

    app(UnsuspendAccount::class)->handle($account, operator: 'jane');

    submitProof();
    $this->assertAuthenticatedAs($account);
    $this->assertDatabaseHas('user_security_events', ['type' => 'account.unsuspended', 'user_id' => $account->getKey(), 'operator' => 'jane']);
});
