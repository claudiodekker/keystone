<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Notifications\SecurityAlert;
use ClaudioDekker\Keystone\SecurityEventType;
use ClaudioDekker\Keystone\Tests\Fixtures\FormTypeSupport;
use ClaudioDekker\Keystone\Tests\Fixtures\WordedType;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

pest()->extend(AppTestCase::class);

beforeEach(function () {
    $this->withoutMandates();
    config(['keystone.methods' => ['form', 'code']]);
});

/**
 * Give the signed-in account a credential of the type, returning its id.
 *
 * @param  array<string, mixed>  $columns
 */
function holdCredential(string $type, ?string $label = null, array $columns = []): int
{
    return DB::table('user_credentials')->insertGetId([
        'user_id' => Keystone::guard()->id(),
        'type' => $type,
        'label' => $label,
        'created_at' => now(),
        ...$columns,
    ]);
}

describe('the confirm step', function () {
    it('names the credential it would remove', function () {
        $this->signInAccount(new FormTypeSupport);
        $id = holdCredential('code', 'Phone');

        $response = $this->get(route('security.credentials.remove', ['credential' => $id]));

        $response->assertOk()->assertExactJson(['id' => $id, 'type' => 'code', 'label' => 'Phone', 'listed' => true]);
    });

    it('names a leftover of a type no longer listed, and a disabled credential', function (string $type, array $columns, bool $listed) {
        $this->signInAccount(new FormTypeSupport);
        $id = holdCredential($type, 'Old key', $columns);

        $response = $this->get(route('security.credentials.remove', ['credential' => $id]));

        $response->assertOk()->assertExactJson(['id' => $id, 'type' => $type, 'label' => 'Old key', 'listed' => $listed]);
    })->with([
        'a leftover' => ['uninstalled', [], false],
        'a disabled credential' => ['code', ['disabled_at' => '2026-09-01 12:00:00'], true],
    ]);

    it('sends the user back to the security page for a credential the account doesn\'t hold', function (int|string $credential) {
        $this->signInAccount(new FormTypeSupport);

        $response = $this->get(route('security.credentials.remove', ['credential' => $credential]));

        $response->assertRedirectToRoute('security');
        $this->get(route('security'))->assertJsonPath('status', __('keystone::messages.status.credential-not-found'));
    })->with([
        'an unknown id' => [fn () => 999],
        'another account\'s credential' => [fn () => DB::table('user_credentials')->insertGetId(['user_id' => $this->createAccount('john@example.com')->getKey(), 'type' => 'code'])],
        'an id that isn\'t a number' => [fn () => 'phone'],
        'an id past the largest integer' => [fn () => '99999999999999999999'],
    ]);

    it('asks for sudo first', function () {
        $this->signInAccount(new FormTypeSupport);
        $id = holdCredential('code', 'Phone');
        $this->delete(route('sudo.end'));

        $response = $this->get(route('security.credentials.remove', ['credential' => $id]));

        $response->assertRedirectToRoute('sudo');
        expect(Keystone::guard()->sudoInProgress()->intendedUrl)->toBe(route('security.credentials.remove', ['credential' => $id], absolute: false));
    });

    it('sends a guest to sign in', function () {
        $this->get(route('security.credentials.remove', ['credential' => 1]))->assertRedirectToRoute('login');
    });

    it('limits requests to the page', function () {
        $this->signInAccount(new FormTypeSupport);
        $id = holdCredential('code', 'Phone');

        foreach (range(1, config()->integer('keystone.rate_limits.requests_per_minute.view')) as $ignored) {
            $this->get(route('security.credentials.remove', ['credential' => $id]))->assertOk();
        }

        $this->get(route('security.credentials.remove', ['credential' => $id]))->assertTooManyRequests();
    });
});

describe('removing', function () {
    it('removes the credential, records it, alerts the owner and says so on the security page', function () {
        $account = $this->signInAccount(new FormTypeSupport);
        $id = holdCredential('code', 'Phone');
        Notification::fake();

        $response = $this->delete(route('security.credentials.remove.submit', ['credential' => $id]));

        $response->assertRedirectToRoute('security');
        $this->assertDatabaseMissing('user_credentials', ['id' => $id]);
        $this->assertDatabaseHas('user_security_events', [
            'type' => 'credential.removed',
            'user_id' => $account->getKey(),
            'actor' => 'user',
            'credential_type' => 'code',
            'credential_id' => $id,
            'credential_label' => 'Phone',
        ]);
        Notification::assertSentOnDemandTimes(SecurityAlert::class, 1);
        Notification::assertSentOnDemand(SecurityAlert::class, fn (SecurityAlert $alert) => $alert->type === SecurityEventType::CREDENTIAL_REMOVED);
        $this->get(route('security'))->assertJsonPath('status', __('keystone::messages.status.credential-removed'));
    });

    it('ends the account\'s other sessions and keeps the remover\'s, rotating its id and keeping its sudo', function () {
        $this->freezeSecond();
        $account = $this->signInAccount(new FormTypeSupport);
        $id = holdCredential('code', 'Phone');
        $sessionId = session()->getId();

        $this->delete(route('security.credentials.remove.submit', ['credential' => $id]));

        expect(DB::table('users')->where('id', $account->getKey())->value('credential_epoch'))->toEqual(1)
            ->and(session()->getId())->not->toBe($sessionId);
        $this->get(route('security'))->assertOk()->assertJsonPath('sudoEndsAt', now()->addMinutes(15)->toIso8601String());
        $this->assertAuthenticatedAs($account);
    });

    it('removes a leftover and a disabled credential', function (string $type, array $columns) {
        $this->signInAccount(new FormTypeSupport);
        $id = holdCredential($type, 'Old key', $columns);

        $response = $this->delete(route('security.credentials.remove.submit', ['credential' => $id]));

        $response->assertRedirectToRoute('security');
        $this->assertDatabaseMissing('user_credentials', ['id' => $id]);
        $this->assertDatabaseHas('user_security_events', ['type' => 'credential.removed', 'credential_type' => $type, 'credential_id' => $id]);
    })->with([
        'a leftover' => ['uninstalled', []],
        'a disabled credential' => ['code', ['disabled_at' => '2026-09-01 12:00:00']],
    ]);

    it('says it with the status the credential\'s type names for a removal, even once the type is no longer listed', function (array $methods) {
        config(['keystone.methods' => $methods]);
        $this->app->make(CredentialTypes::class)->register(new WordedType);
        $this->signInAccount(new FormTypeSupport);
        $id = holdCredential('worded');

        $response = $this->delete(route('security.credentials.remove.submit', ['credential' => $id]));

        $response->assertRedirectToRoute('security');
        $this->assertDatabaseMissing('user_credentials', ['id' => $id]);
        $this->get(route('security'))->assertJsonPath('status', __('keystone::messages.status.session-revoked'));
    })->with([
        'listed' => [['form', 'worded']],
        'no longer listed' => [['form']],
    ]);

    it('removes nothing for a credential the account doesn\'t hold, and says so on the security page', function (int|string $credential) {
        $account = $this->signInAccount(new FormTypeSupport);
        $credentials = DB::table('user_credentials')->count();

        $response = $this->delete(route('security.credentials.remove.submit', ['credential' => $credential]));

        $response->assertRedirectToRoute('security');
        $this->assertDatabaseCount('user_credentials', $credentials);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'credential.removed']);
        $this->assertDatabaseHas('users', ['id' => $account->getKey(), 'credential_epoch' => 0]);
        $this->get(route('security'))->assertJsonPath('status', __('keystone::messages.status.credential-not-found'));
    })->with([
        'an unknown id' => [fn () => 999],
        'another account\'s credential' => [fn () => DB::table('user_credentials')->insertGetId(['user_id' => $this->createAccount('john@example.com')->getKey(), 'type' => 'code'])],
        'an id that isn\'t a number' => [fn () => 'phone'],
    ]);

    it('asks for sudo first, removing nothing', function () {
        $this->signInAccount(new FormTypeSupport);
        $id = holdCredential('code', 'Phone');
        $this->delete(route('sudo.end'));

        $response = $this->from(route('security.credentials.remove', ['credential' => $id]))->delete(route('security.credentials.remove.submit', ['credential' => $id]));

        $response->assertRedirectToRoute('sudo');
        $this->assertDatabaseHas('user_credentials', ['id' => $id]);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'credential.removed']);
    });

    it('sends a guest to sign in', function () {
        $this->delete(route('security.credentials.remove.submit', ['credential' => 1]))->assertRedirectToRoute('login');
    });

    it('takes the change limit', function () {
        $this->signInAccount(new FormTypeSupport);

        foreach (range(1, config()->integer('keystone.rate_limits.requests_per_minute.change')) as $ignored) {
            $this->delete(route('security.credentials.remove.submit', ['credential' => 999]))->assertRedirectToRoute('security');
        }

        $this->delete(route('security.credentials.remove.submit', ['credential' => 999]))->assertTooManyRequests();
    });
});

/**
 * Run the race once, as the first query inside the account change reaches the users table, before the change takes the account's lock.
 */
function raceBeforeTheLock(Closure $race): void
{
    $raced = false;
    $outerLevel = DB::transactionLevel();

    DB::beforeExecuting(function (string $query, array $bindings, Connection $connection) use (&$raced, $outerLevel, $race) {
        if (! $raced && $connection->transactionLevel() > $outerLevel && str_contains($query, 'users')) {
            $raced = true;
            $race();
        }
    });
}

/**
 * Assert the removal was refused with the message, leaving the credential, the epoch and the trail as they were.
 *
 * @param  TestResponse<Response>  $response
 */
function assertKept(TestResponse $response, int $id, string $message): void
{
    $response->assertRedirectToRoute('security.credentials.remove', ['credential' => $id])
        ->assertSessionHasErrors(['credential' => $message]);

    test()->assertDatabaseHas('user_credentials', ['id' => $id]);
    test()->assertDatabaseMissing('user_security_events', ['type' => 'credential.removed']);
    test()->assertDatabaseHas('users', ['credential_epoch' => 0]);
}

describe('the last way to sign in', function () {
    it('is kept', function () {
        $this->signInAccount(new FormTypeSupport);
        $id = DB::table('user_credentials')->value('id');

        $response = $this->delete(route('security.credentials.remove.submit', ['credential' => $id]));

        assertKept($response, $id, 'You cannot remove your only way to sign in.');
        $this->assertAuthenticated();
    });

    it('is kept when the others can\'t sign in: disabled, a leftover, or a type that doesn\'t serve sign-in', function (string $type, array $columns) {
        $this->signInAccount(new FormTypeSupport);
        $id = DB::table('user_credentials')->value('id');
        holdCredential($type, columns: $columns);

        $response = $this->delete(route('security.credentials.remove.submit', ['credential' => $id]));

        assertKept($response, $id, 'You cannot remove your only way to sign in.');
    })->with([
        'disabled' => ['form', ['disabled_at' => '2026-09-01 12:00:00']],
        'a leftover' => ['uninstalled', []],
        'a type listed but not on sign-in' => ['code', []],
    ]);

    it('can be removed once another way to sign in remains', function () {
        $this->signInAccount(new FormTypeSupport);
        $id = DB::table('user_credentials')->value('id');
        holdCredential('form', 'Spare');

        $response = $this->delete(route('security.credentials.remove.submit', ['credential' => $id]));

        $response->assertRedirectToRoute('security');
        $this->assertDatabaseMissing('user_credentials', ['id' => $id]);
    });

    it('doesn\'t keep a credential that can\'t sign in, whatever else the account holds', function (string $type, array $columns) {
        $this->signInAccount(new FormTypeSupport);
        DB::table('user_credentials')->update(['disabled_at' => now()]);
        $id = holdCredential($type, columns: $columns);

        $response = $this->delete(route('security.credentials.remove.submit', ['credential' => $id]));

        $response->assertRedirectToRoute('security');
        $this->assertDatabaseMissing('user_credentials', ['id' => $id]);
    })->with([
        'a disabled one' => ['form', ['disabled_at' => '2026-09-01 12:00:00']],
        'a leftover' => ['uninstalled', []],
    ]);

    it('is kept when another removal took the other way to sign in just before this one took the lock', function () {
        $this->signInAccount(new FormTypeSupport);
        $id = DB::table('user_credentials')->value('id');
        $other = holdCredential('form', 'Spare');
        raceBeforeTheLock(fn () => DB::table('user_credentials')->delete($other));

        $response = $this->delete(route('security.credentials.remove.submit', ['credential' => $id]));

        assertKept($response, $id, 'You cannot remove your only way to sign in.');
    });
});

describe('the last second factor', function () {
    beforeEach(function () {
        $this->signInAccount(new FormTypeSupport);
        config(['keystone.require_second_factor' => true]);
    });

    it('is kept while the app requires a second factor', function () {
        $id = holdCredential('code', 'Phone');

        $response = $this->delete(route('security.credentials.remove.submit', ['credential' => $id]));

        assertKept($response, $id, 'You cannot remove your last two-factor credential while two-factor authentication is required.');
        $this->assertAuthenticated();
    });

    it('is kept when the others don\'t count as a second factor: disabled, a leftover, or a type not listed on the challenge', function (string $type, array $columns) {
        $id = holdCredential('code', 'Phone');
        holdCredential($type, columns: $columns);

        $response = $this->delete(route('security.credentials.remove.submit', ['credential' => $id]));

        assertKept($response, $id, 'You cannot remove your last two-factor credential while two-factor authentication is required.');
    })->with([
        'disabled' => ['code', ['disabled_at' => '2026-09-01 12:00:00']],
        'a leftover' => ['uninstalled', []],
        'a sign-in type' => ['form', []],
    ]);

    it('can be removed once another second factor remains, or when the app doesn\'t require one', function (Closure $arrange) {
        $id = holdCredential('code', 'Phone');
        $arrange();

        $response = $this->delete(route('security.credentials.remove.submit', ['credential' => $id]));

        $response->assertRedirectToRoute('security');
        $this->assertDatabaseMissing('user_credentials', ['id' => $id]);
    })->with([
        'another second factor' => [fn () => holdCredential('code', 'Tablet')],
        'no mandate' => [fn () => config(['keystone.require_second_factor' => false])],
    ]);

    it('doesn\'t keep a credential that counts as no second factor', function (string $type, array $columns) {
        holdCredential('code', 'Phone');
        $id = holdCredential($type, columns: $columns);

        $response = $this->delete(route('security.credentials.remove.submit', ['credential' => $id]));

        $response->assertRedirectToRoute('security');
        $this->assertDatabaseMissing('user_credentials', ['id' => $id]);
    })->with([
        'a disabled one' => ['code', ['disabled_at' => '2026-09-01 12:00:00']],
        'a leftover' => ['uninstalled', []],
    ]);

    it('is kept when another removal took the other second factor just before this one took the lock', function () {
        $id = holdCredential('code', 'Phone');
        $other = holdCredential('code', 'Tablet');
        raceBeforeTheLock(fn () => DB::table('user_credentials')->delete($other));

        $response = $this->delete(route('security.credentials.remove.submit', ['credential' => $id]));

        assertKept($response, $id, 'You cannot remove your last two-factor credential while two-factor authentication is required.');
    });
});

describe('a sign-in racing the removal', function () {
    it('doesn\'t outlive the removal of the credential it proved', function () {
        $account = $this->createAccount();
        $this->arrangeCredential($account, new FormTypeSupport, Surface::SIGN_IN);
        $id = DB::table('user_credentials')->value('id');
        DB::table('user_credentials')->insertGetId(['user_id' => $account->getKey(), 'type' => 'form', 'label' => 'Spare']);
        raceBeforeTheLock(function () use ($account, $id) {
            DB::table('user_credentials')->delete($id);
            DB::table('users')->where('id', $account->getKey())->increment('credential_epoch');
        });

        $this->submitSignIn(new FormTypeSupport, 'jane@example.com', (new FormTypeSupport)->validProof(Surface::SIGN_IN));

        $this->get(route('security'))->assertRedirectToRoute('login');
        $this->assertGuest();
    });
    it('is refused when the credential it proved is removed before its write', function () {
        $account = $this->createAccount();
        $this->arrangeCredential($account, new FormTypeSupport, Surface::SIGN_IN);
        $id = DB::table('user_credentials')->value('id');
        DB::table('user_credentials')->insertGetId(['user_id' => $account->getKey(), 'type' => 'form', 'label' => 'Spare']);
        raceBeforeTheLock(fn () => DB::table('user_credentials')->delete($id));

        $this->submitSignIn(new FormTypeSupport, 'jane@example.com', (new FormTypeSupport)->validProof(Surface::SIGN_IN));

        $this->assertGuest();
        $this->assertDatabaseHas('user_security_events', ['type' => 'proof.rejected', 'user_id' => $account->getKey(), 'reason' => 'keystone.superseded']);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'signed_in']);
    });
});
