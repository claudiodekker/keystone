<?php

use ClaudioDekker\Keystone\Actions\AddCredential;
use ClaudioDekker\Keystone\Actions\EndSessions;
use ClaudioDekker\Keystone\Actions\RehashCredential;
use ClaudioDekker\Keystone\Actions\SuspendAccount;
use ClaudioDekker\Keystone\Actions\UnsuspendAccount;
use ClaudioDekker\Keystone\Actor;
use ClaudioDekker\Keystone\Credentials;
use ClaudioDekker\Keystone\KeystoneGuard;
use ClaudioDekker\Keystone\Methods\StoredCredential;
use ClaudioDekker\Keystone\Notifications\SecurityAlert;
use ClaudioDekker\Keystone\SecurityEvent;
use ClaudioDekker\Keystone\SecurityEventType;
use ClaudioDekker\Keystone\Tests\Fixtures\FormType;
use ClaudioDekker\Keystone\Tests\Fixtures\ProbeAccountWrite;
use ClaudioDekker\Keystone\Tests\Fixtures\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

function guard(): KeystoneGuard
{
    return Auth::guard('web');
}

function write(User $user, Closure $write): mixed
{
    return (new ProbeAccountWrite)->handle($user, $write);
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
    $id = (new AddCredential)->handle($user, new FormType, identifier: null, secret: $secret);

    return new StoredCredential($id, identifier: null, secret: $secret, label: null);
}

test('the credential epoch moves only for a write that removes, replaces or ends something', function (Closure $apply, bool $moves, ?string $suspendedAt = null) {
    $this->freezeSecond();
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->getKey())->update(['suspended_at' => $suspendedAt]);
    $credential = storeCredential($user);

    $apply($user, $credential);

    $row = DB::table('users')->where('id', $user->getKey())->first();

    expect($row->credential_epoch)->toEqual($moves ? 1 : 0)
        ->and($row->credential_epoch_moved_at)->toEqual($moves ? now()->toDateTimeString() : null);
})->with([
    'nothing' => [fn (User $user) => write($user, fn () => null), false],
    'adding a credential' => [fn (User $user) => (new AddCredential)->handle($user, new FormType, identifier: null, secret: 'new'), false],
    'a rehash' => [fn (User $user, StoredCredential $credential) => (new RehashCredential)->handle($user, $credential, type: 'form', secret: 'new-hash'), false],
    'ending sessions' => [fn (User $user) => (new EndSessions)->handle($user), true],
    'suspending' => [fn (User $user) => (new SuspendAccount)->handle($user), true],
    'unsuspending' => [fn (User $user) => (new UnsuspendAccount)->handle($user), false, '2026-09-01 12:00:00'],
]);

it('moves the epoch once however many times the write ends sessions', function () {
    $user = User::factory()->create();

    write($user, function (User $account, ProbeAccountWrite $write) {
        $write->endSessionsOf($account);
        $write->endSessionsOf($account);
    });

    expect(epochOf($user))->toBe(1);
});

it('applies a rehash only while the credential still holds the secret that was verified', function () {
    $user = User::factory()->create();
    $credential = storeCredential($user);
    $stale = new StoredCredential($credential->id, identifier: null, secret: 'stale-hash', label: null);

    $replaced = (new RehashCredential)->handle($user, $stale, type: 'form', secret: 'new-hash');

    expect($replaced)->toBeFalse()
        ->and(Crypt::decryptString(DB::table('user_credentials')->value('secret')))->toBe('old-hash');
});

it('hands the write the account read fresh, whatever the caller holds', function () {
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->getKey())->update(['credential_epoch' => 4]);

    $seen = write($user, fn (User $account) => $account->getRawOriginal('credential_epoch'));

    expect($seen)->toEqual(4);
});

it('moves the epoch on from the one the account holds', function () {
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->getKey())->update(['credential_epoch' => 4]);

    write($user, fn (User $account, ProbeAccountWrite $write) => $write->endSessionsOf($account));

    expect(epochOf($user))->toBe(5);
});

it('reads the recipients before the write applies', function (array $addresses, array $recipients) {
    $user = User::factory()->create();
    holdAddresses($user, $addresses);

    $snapshot = write($user, function (User $account, ProbeAccountWrite $write) {
        DB::table('user_emails')->delete();

        return $write->recipients();
    });

    expect($snapshot)->toBe($recipients);
})->with([
    'every verified address' => [['jane@example.com' => true, 'old@example.com' => false, 'work@example.com' => true], ['jane@example.com', 'work@example.com']],
    'the unverified ones when none is verified' => [['jane@example.com' => false, 'work@example.com' => false], ['jane@example.com', 'work@example.com']],
    'none' => [[], []],
]);

it('records the write\'s events once it commits', function () {
    $user = User::factory()->create();

    write($user, function (User $account, ProbeAccountWrite $write) {
        $write->recordAbout($account, SecurityEventType::SESSIONS_TERMINATED, operator: 'jane');

        $this->assertDatabaseCount('user_security_events', 0);
    });

    $event = SecurityEvent::query()->sole();

    expect($event->type)->toBe(SecurityEventType::SESSIONS_TERMINATED)
        ->and($event->user_id)->toEqual($user->getKey())
        ->and($event->actor)->toBe(Actor::OPERATOR)
        ->and($event->operator)->toBe('jane');
});

it('alerts the recipients read before the write applies', function () {
    Notification::fake();
    $user = User::factory()->create();
    holdAddresses($user, ['jane@example.com' => true]);

    write($user, function (User $account, ProbeAccountWrite $write) {
        DB::table('user_emails')->delete();

        $write->recordAbout($account, SecurityEventType::SESSIONS_TERMINATED);
    });

    Notification::assertSentOnDemand(SecurityAlert::class, fn (SecurityAlert $alert, array $channels, object $notifiable) => $notifiable->routes['mail'] === 'jane@example.com');
});

it('records the event without alerting when the write suppresses the alert', function () {
    Notification::fake();
    $user = User::factory()->create();
    holdAddresses($user, ['jane@example.com' => true]);

    write($user, fn (User $account, ProbeAccountWrite $write) => $write->recordAbout($account, SecurityEventType::SESSIONS_TERMINATED, alert: false));

    Notification::assertNothingSent();
    $this->assertDatabaseCount('user_security_events', 1);
});

it('rolls back and records nothing when the write fails', function () {
    $user = User::factory()->create();

    try {
        write($user, function (User $account, ProbeAccountWrite $write) {
            (new Credentials(guard()->userModel()))->store($account, new FormType, identifier: null, secret: 'new');
            $write->endSessionsOf($account);
            $write->recordAbout($account, SecurityEventType::SESSIONS_TERMINATED);

            throw new RuntimeException('The write failed.');
        });
    } catch (RuntimeException) {
        //
    }

    $this->assertDatabaseCount('user_credentials', 0);
    $this->assertDatabaseCount('user_security_events', 0);
    expect(epochOf($user))->toBe(0);
});

it('records nothing when a transaction around the write rolls back', function () {
    $user = User::factory()->create();

    DB::beginTransaction();
    write($user, function (User $account, ProbeAccountWrite $write) {
        $write->endSessionsOf($account);
        $write->recordAbout($account, SecurityEventType::SESSIONS_TERMINATED);
    });
    DB::rollBack();

    $this->assertDatabaseCount('user_security_events', 0);
});

describe('the mover\'s own session', function () {
    it('keeps it signed in, on a new session id, when the write ends sessions', function () {
        $user = User::factory()->create();
        guard()->signIn($user);
        $before = session()->getId();

        write($user, fn (User $account, ProbeAccountWrite $write) => $write->endSessionsOf($account));

        Auth::forgetGuards();
        expect(guard()->user()?->getKey())->toBe($user->getKey())
            ->and(session()->getId())->not->toBe($before);
    });

    it('leaves its session id alone when the epoch doesn\'t move', function () {
        $user = User::factory()->create();
        guard()->signIn($user);
        $before = session()->getId();

        (new AddCredential)->handle($user, new FormType, identifier: null, secret: 'new');

        expect(session()->getId())->toBe($before);
    });

    it('doesn\'t carry a session signed in as another account', function () {
        $admin = User::factory()->create();
        $user = User::factory()->create();
        guard()->signIn($admin);
        $before = session()->getId();

        write($user, fn (User $account, ProbeAccountWrite $write) => $write->endSessionsOf($account));

        Auth::forgetGuards();
        expect(guard()->user()?->getKey())->toBe($admin->getKey())
            ->and(session()->getId())->toBe($before);
    });

    it('doesn\'t revive a session already on an older epoch', function () {
        $user = User::factory()->create();
        guard()->signIn($user);
        DB::table('users')->where('id', $user->getKey())->increment('credential_epoch');

        write($user, fn (User $account, ProbeAccountWrite $write) => $write->endSessionsOf($account));

        Auth::forgetGuards();
        expect(guard()->user())->toBeNull();
    });
});
