<?php

use ClaudioDekker\Keystone\Actor;
use ClaudioDekker\Keystone\AlreadySuspended;
use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\Jobs\SuspendAccount;
use ClaudioDekker\Keystone\Jobs\UnsuspendAccount;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\NotSuspended;
use ClaudioDekker\Keystone\SecurityEvent;
use ClaudioDekker\Keystone\SecurityEventType;
use ClaudioDekker\Keystone\Tests\Fixtures\FormTypeSupport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

pest()->extend(AppTestCase::class);

beforeEach(function () {
    Route::middleware('web')->get('whoami', fn () => auth()->id() ?? 'guest');
});

/**
 * Submit a valid proof for the account's address on the sign-in page.
 */
function signInAgain(): void
{
    test()->post(route('login.submit', ['type' => 'form']), [
        'identifier' => 'jane@example.com',
        ...(new FormTypeSupport)->validProof(Surface::SIGN_IN),
    ]);
}

describe('keystone:suspend', function () {
    it('ends the account\'s live session on its next request', function () {
        $account = $this->signInAccount(new FormTypeSupport);
        session()->flush();

        $this->artisan('keystone:suspend', ['user' => (string) $account->getKey()])->assertSuccessful();

        $response = $this->get('whoami');

        $response->assertContent('guest');
        expect(DB::table('users')->value('credential_epoch'))->toEqual(1);
    });

    it('bars the account from signing in', function () {
        $account = $this->createAccount();
        $this->arrangeCredential($account, new FormTypeSupport, Surface::SIGN_IN);

        $this->artisan('keystone:suspend', ['user' => (string) $account->getKey()])->assertSuccessful();

        signInAgain();

        $this->assertGuest();
    });

    it('keeps the account\'s address holds', function () {
        $account = $this->createAccount();
        DB::table('user_emails')->update(['verified_address' => 'jane@example.com']);

        $this->artisan('keystone:suspend', ['user' => (string) $account->getKey()])->assertSuccessful();

        $this->assertDatabaseHas('user_emails', ['user_id' => $account->getKey(), 'verified_address' => 'jane@example.com']);
    });

    it('records that an operator suspended the account, and which one', function () {
        $account = $this->createAccount();

        $this->artisan('keystone:suspend', ['user' => (string) $account->getKey(), '--operator' => 'jane@ops'])->assertSuccessful();

        $event = SecurityEvent::query()->sole();

        expect($event->only(['type', 'user_id', 'actor', 'operator']))->toEqual([
            'type' => SecurityEventType::ACCOUNT_SUSPENDED,
            'user_id' => $account->getKey(),
            'actor' => Actor::OPERATOR,
            'operator' => 'jane@ops',
        ]);
    });

    it('refuses an account that is already suspended, and leaves it as it is', function () {
        $account = $this->createAccount();
        DB::table('users')->update(['suspended_at' => '2026-09-01 12:00:00']);

        $this->artisan('keystone:suspend', ['user' => (string) $account->getKey()])
            ->expectsOutputToContain("Account [{$account->getKey()}] is already suspended.")
            ->assertFailed();

        $this->assertDatabaseCount('user_security_events', 0);
        expect(DB::table('users')->first(['credential_epoch', 'suspended_at']))->toEqual((object) ['credential_epoch' => 0, 'suspended_at' => '2026-09-01 12:00:00']);
    });

    it('refuses an id no account has', function () {
        $this->artisan('keystone:suspend', ['user' => '999'])
            ->expectsOutputToContain('No account has the id [999].')
            ->assertFailed();

        $this->assertDatabaseCount('user_security_events', 0);
    });
});

describe('keystone:unsuspend', function () {
    it('lets the account sign in again', function () {
        $account = $this->createAccount();
        $this->arrangeCredential($account, new FormTypeSupport, Surface::SIGN_IN);
        $this->artisan('keystone:suspend', ['user' => (string) $account->getKey()])->assertSuccessful();

        $this->artisan('keystone:unsuspend', ['user' => (string) $account->getKey()])->assertSuccessful();

        signInAgain();

        $this->assertAuthenticatedAs($account);
        expect(DB::table('users')->value('credential_epoch'))->toEqual(1);
    });

    it('records that an operator unsuspended the account, and which one', function () {
        $account = $this->createAccount();
        DB::table('users')->update(['suspended_at' => now()]);

        $this->artisan('keystone:unsuspend', ['user' => (string) $account->getKey(), '--operator' => 'jane@ops'])->assertSuccessful();

        $event = SecurityEvent::query()->sole();

        expect($event->only(['type', 'user_id', 'actor', 'operator']))->toEqual([
            'type' => SecurityEventType::ACCOUNT_UNSUSPENDED,
            'user_id' => $account->getKey(),
            'actor' => Actor::OPERATOR,
            'operator' => 'jane@ops',
        ]);
    });

    it('refuses an account that isn\'t suspended', function () {
        $account = $this->createAccount();

        $this->artisan('keystone:unsuspend', ['user' => (string) $account->getKey()])
            ->expectsOutputToContain("Account [{$account->getKey()}] isn't suspended.")
            ->assertFailed();

        $this->assertDatabaseCount('user_security_events', 0);
    });

    it('refuses an id no account has', function () {
        $this->artisan('keystone:unsuspend', ['user' => '999'])
            ->expectsOutputToContain('No account has the id [999].')
            ->assertFailed();

        $this->assertDatabaseCount('user_security_events', 0);
    });
});

describe('the suspension jobs', function () {
    it('refuses to suspend an account that already is', function () {
        $account = $this->createAccount();
        SuspendAccount::dispatchSync($account, operator: 'jane@ops');

        SuspendAccount::dispatchSync($account, operator: 'jane@ops');
    })->throws(AlreadySuspended::class);

    it('refuses to unsuspend an account that isn\'t suspended', function () {
        $account = $this->createAccount();

        UnsuspendAccount::dispatchSync($account, operator: 'jane@ops');
    })->throws(NotSuspended::class);
});
