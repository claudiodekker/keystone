<?php

use ClaudioDekker\Keystone\AccountChange;
use ClaudioDekker\Keystone\Actions\ChangeAccount;
use ClaudioDekker\Keystone\Actor;
use ClaudioDekker\Keystone\KeystoneGuard;
use ClaudioDekker\Keystone\Methods\StoredCredential;
use ClaudioDekker\Keystone\Notifications\SecurityAlert;
use ClaudioDekker\Keystone\SecurityEvent;
use ClaudioDekker\Keystone\SecurityEventType;
use ClaudioDekker\Keystone\Tests\Fixtures\FormType;
use ClaudioDekker\Keystone\Tests\Fixtures\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

function guard(): KeystoneGuard
{
    return Auth::guard('web');
}

function changes(): ChangeAccount
{
    return new ChangeAccount(guard());
}

function epochOf(User $user): int
{
    return (int) DB::table('users')->where('id', $user->getKey())->value('credential_epoch');
}

function holdAddresses(User $user, array $addresses): void
{
    foreach ($addresses as $address => $verified) {
        DB::table('user_emails')->insert([
            'user_id' => $user->getKey(),
            'address' => $address,
            'verified_at' => $verified ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

function storeCredential(User $user, string $secret = 'old-hash'): StoredCredential
{
    $id = changes()->handle($user, fn (AccountChange $change) => $change->addCredential(new FormType, identifier: null, secret: $secret));

    return new StoredCredential($id, identifier: null, secret: $secret, label: null);
}

test('the credential epoch moves only for a change that removes, replaces or ends something', function (Closure $apply, bool $moves, ?string $suspendedAt = null) {
    $this->freezeSecond();
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->getKey())->update(['suspended_at' => $suspendedAt]);
    $credential = storeCredential($user);

    changes()->handle($user, fn (AccountChange $change) => $apply($change, $credential));

    $row = DB::table('users')->where('id', $user->getKey())->first();

    expect($row->credential_epoch)->toEqual($moves ? 1 : 0)
        ->and($row->credential_epoch_moved_at)->toEqual($moves ? now()->toDateTimeString() : null);
})->with([
    'nothing' => [fn (AccountChange $change) => null, false],
    'adding a credential' => [fn (AccountChange $change) => $change->addCredential(new FormType, identifier: null, secret: 'new'), false],
    'a rehash' => [fn (AccountChange $change, StoredCredential $credential) => $change->rehash($credential, type: 'form', secret: 'new-hash'), false],
    'ending sessions' => [fn (AccountChange $change) => $change->endSessions(), true],
    'suspending' => [fn (AccountChange $change) => $change->suspend(), true],
    'unsuspending' => [fn (AccountChange $change) => $change->unsuspend(), false, '2026-09-01 12:00:00'],
]);

it('moves the epoch once however many times the change ends sessions', function () {
    $user = User::factory()->create();

    changes()->handle($user, function (AccountChange $change) {
        $change->endSessions();
        $change->endSessions();
    });

    expect(epochOf($user))->toBe(1);
});

it('stamps the account suspended at the time it is suspended', function () {
    $this->freezeSecond();
    $user = User::factory()->create();

    changes()->handle($user, fn (AccountChange $change) => $change->suspend());

    expect(DB::table('users')->where('id', $user->getKey())->value('suspended_at'))->toEqual(now()->toDateTimeString());
});

it('clears the stamp to unsuspend the account', function () {
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->getKey())->update(['suspended_at' => now()]);

    changes()->handle($user, fn (AccountChange $change) => $change->unsuspend());

    expect(DB::table('users')->where('id', $user->getKey())->value('suspended_at'))->toBeNull();
});

it('applies a rehash only while the credential still holds the secret that was verified', function () {
    $user = User::factory()->create();
    $credential = storeCredential($user);
    $stale = new StoredCredential($credential->id, identifier: null, secret: 'stale-hash', label: null);

    $replaced = changes()->handle($user, fn (AccountChange $change) => $change->rehash($stale, type: 'form', secret: 'new-hash'));

    expect($replaced)->toBeFalse()
        ->and(Crypt::decryptString(DB::table('user_credentials')->value('secret')))->toBe('old-hash');
});

it('hands the change the account read fresh, whatever the caller holds', function () {
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->getKey())->update(['credential_epoch' => 4]);

    $seen = changes()->handle($user, fn (AccountChange $change) => $change->account->getRawOriginal('credential_epoch'));

    expect($seen)->toEqual(4);
});

it('moves the epoch on from the one the account holds', function () {
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->getKey())->update(['credential_epoch' => 4]);

    changes()->handle($user, fn (AccountChange $change) => $change->endSessions());

    expect(epochOf($user))->toBe(5);
});

it('reads the recipients before the change applies', function (array $addresses, array $recipients) {
    $user = User::factory()->create();
    holdAddresses($user, $addresses);

    $snapshot = changes()->handle($user, function (AccountChange $change) {
        DB::table('user_emails')->delete();

        return $change->recipients;
    });

    expect($snapshot)->toBe($recipients);
})->with([
    'every verified address' => [['jane@example.com' => true, 'old@example.com' => false, 'work@example.com' => true], ['jane@example.com', 'work@example.com']],
    'the unverified ones when none is verified' => [['jane@example.com' => false, 'work@example.com' => false], ['jane@example.com', 'work@example.com']],
    'none' => [[], []],
]);

it('records the change\'s events once it commits', function () {
    $user = User::factory()->create();

    changes()->handle($user, function (AccountChange $change) {
        $change->record(SecurityEventType::SESSIONS_TERMINATED, actor: Actor::OPERATOR, operator: 'jane');

        $this->assertDatabaseCount('user_security_events', 0);
    });

    $event = SecurityEvent::query()->sole();

    expect($event->type)->toBe(SecurityEventType::SESSIONS_TERMINATED)
        ->and($event->user_id)->toEqual($user->getKey())
        ->and($event->actor)->toBe(Actor::OPERATOR)
        ->and($event->operator)->toBe('jane');
});

it('alerts the recipients read before the change applies', function () {
    Notification::fake();
    $user = User::factory()->create();
    holdAddresses($user, ['jane@example.com' => true]);

    changes()->handle($user, function (AccountChange $change) {
        DB::table('user_emails')->delete();

        $change->record(SecurityEventType::SESSIONS_TERMINATED, actor: Actor::OPERATOR);
    });

    Notification::assertSentOnDemand(SecurityAlert::class, fn (SecurityAlert $alert, array $channels, object $notifiable) => $notifiable->routes['mail'] === 'jane@example.com');
});

it('records the event without alerting when the change suppresses the alert', function () {
    Notification::fake();
    $user = User::factory()->create();
    holdAddresses($user, ['jane@example.com' => true]);

    changes()->handle($user, fn (AccountChange $change) => $change->record(SecurityEventType::SESSIONS_TERMINATED, actor: Actor::OPERATOR, alert: false));

    Notification::assertNothingSent();
    $this->assertDatabaseCount('user_security_events', 1);
});

it('rolls back and records nothing when the change fails', function () {
    $user = User::factory()->create();

    try {
        changes()->handle($user, function (AccountChange $change) {
            $change->addCredential(new FormType, identifier: null, secret: 'new');
            $change->endSessions();
            $change->record(SecurityEventType::SESSIONS_TERMINATED, actor: Actor::OPERATOR);

            throw new RuntimeException('The change failed.');
        });
    } catch (RuntimeException) {
        //
    }

    $this->assertDatabaseCount('user_credentials', 0);
    $this->assertDatabaseCount('user_security_events', 0);
    expect(epochOf($user))->toBe(0);
});

it('records nothing when a transaction around the change rolls back', function () {
    $user = User::factory()->create();

    DB::beginTransaction();
    changes()->handle($user, function (AccountChange $change) {
        $change->endSessions();
        $change->record(SecurityEventType::SESSIONS_TERMINATED, actor: Actor::OPERATOR);
    });
    DB::rollBack();

    $this->assertDatabaseCount('user_security_events', 0);
});

describe('the mover\'s own session', function () {
    it('keeps it signed in, on a new session id, when the change ends sessions', function () {
        $user = User::factory()->create();
        guard()->signIn($user);
        $before = session()->getId();

        changes()->handle($user, fn (AccountChange $change) => $change->endSessions());

        Auth::forgetGuards();
        expect(guard()->user()?->getKey())->toBe($user->getKey())
            ->and(session()->getId())->not->toBe($before);
    });

    it('leaves its session id alone when the epoch doesn\'t move', function () {
        $user = User::factory()->create();
        guard()->signIn($user);
        $before = session()->getId();

        changes()->handle($user, fn (AccountChange $change) => $change->addCredential(new FormType, identifier: null, secret: 'new'));

        expect(session()->getId())->toBe($before);
    });

    it('doesn\'t carry a session signed in as another account', function () {
        $admin = User::factory()->create();
        $user = User::factory()->create();
        guard()->signIn($admin);
        $before = session()->getId();

        changes()->handle($user, fn (AccountChange $change) => $change->endSessions());

        Auth::forgetGuards();
        expect(guard()->user()?->getKey())->toBe($admin->getKey())
            ->and(session()->getId())->toBe($before);
    });

    it('doesn\'t revive a session already on an older epoch', function () {
        $user = User::factory()->create();
        guard()->signIn($user);
        DB::table('users')->where('id', $user->getKey())->increment('credential_epoch');

        changes()->handle($user, fn (AccountChange $change) => $change->endSessions());

        Auth::forgetGuards();
        expect(guard()->user())->toBeNull();
    });
});
