<?php

use ClaudioDekker\Keystone\AccountWrite;
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

/**
 * Every write through ChangesAccounts, keyed by what it does: its class, how to run it, and whether it moves the epoch.
 */
function accountWrites(): array
{
    return [
        'nothing' => [ProbeAccountWrite::class, fn (User $user) => write($user, fn () => null), false],
        'adding a credential' => [AddCredential::class, fn (User $user) => (new AddCredential)->handle($user, new FormType, identifier: null, secret: 'new'), false],
        'a rehash' => [RehashCredential::class, fn (User $user, StoredCredential $credential) => (new RehashCredential)->handle($user, $credential, type: 'form', secret: 'new-hash'), false],
        'ending sessions' => [EndSessions::class, fn (User $user) => (new EndSessions)->handle($user), true],
        'suspending' => [SuspendAccount::class, fn (User $user) => (new SuspendAccount)->handle($user), true],
        'unsuspending' => [UnsuspendAccount::class, fn (User $user) => (new UnsuspendAccount)->handle($user), false, '2026-09-01 12:00:00'],
    ];
}

test('the credential epoch moves only for a write that removes, replaces or ends something', function (string $write, Closure $apply, bool $moves, ?string $suspendedAt = null) {
    $this->freezeSecond();
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->getKey())->update(['suspended_at' => $suspendedAt]);
    $credential = storeCredential($user);

    $apply($user, $credential);

    $row = DB::table('users')->where('id', $user->getKey())->first();

    expect($row->credential_epoch)->toEqual($moves ? 1 : 0)
        ->and($row->credential_epoch_moved_at)->toEqual($moves ? now()->toDateTimeString() : null);
})->with(accountWrites());

test('every account write in every package has a row in the epoch table', function () {
    $tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 3), FilesystemIterator::SKIP_DOTS));
    $writes = [];

    foreach ($tree as $file) {
        $path = $file->getPathname();

        if (! preg_match('#/packages/[^/]+/src/.+\.php$#', $path)) {
            continue;
        }

        $contents = file_get_contents($path);

        if (! preg_match('/^\s*use\s+[^;]*\bChangesAccounts\b[^;]*;/m', $contents) || str_ends_with($path, 'Concerns/ChangesAccounts.php')) {
            continue;
        }

        preg_match('/^namespace (.+);$/m', $contents, $namespace);
        $writes[] = $namespace[1].'\\'.$file->getBasename('.php');
    }

    $tabled = array_column(accountWrites(), 0);

    expect($writes)->not->toBeEmpty()
        ->and(array_values(array_diff($writes, $tabled)))->toBe([]);
});

it('moves the epoch once however many times the write ends sessions', function () {
    $user = User::factory()->create();

    write($user, function (AccountWrite $write) {
        $write->endSessions();
        $write->endSessions();
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

    $seen = write($user, fn (AccountWrite $write) => $write->account->getRawOriginal('credential_epoch'));

    expect($seen)->toEqual(4);
});

it('moves the epoch on from the one the account holds', function () {
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->getKey())->update(['credential_epoch' => 4]);

    write($user, fn (AccountWrite $write) => $write->endSessions());

    expect(epochOf($user))->toBe(5);
});

it('reads the recipients before the write applies', function (array $addresses, array $recipients) {
    $user = User::factory()->create();
    holdAddresses($user, $addresses);

    $snapshot = write($user, function (AccountWrite $write) {
        DB::table('user_emails')->delete();

        return $write->recipients;
    });

    expect($snapshot)->toBe($recipients);
})->with([
    'every verified address' => [['jane@example.com' => true, 'old@example.com' => false, 'work@example.com' => true], ['jane@example.com', 'work@example.com']],
    'the unverified ones when none is verified' => [['jane@example.com' => false, 'work@example.com' => false], ['jane@example.com', 'work@example.com']],
    'none' => [[], []],
]);

it('records the write\'s events once it commits', function () {
    $user = User::factory()->create();

    write($user, function (AccountWrite $write) {
        $write->record(SecurityEventType::SESSIONS_TERMINATED, actor: Actor::OPERATOR, operator: 'jane');

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

    write($user, function (AccountWrite $write) {
        DB::table('user_emails')->delete();

        $write->record(SecurityEventType::SESSIONS_TERMINATED);
    });

    Notification::assertSentOnDemand(SecurityAlert::class, fn (SecurityAlert $alert, array $channels, object $notifiable) => $notifiable->routes['mail'] === 'jane@example.com');
});

it('records the event without alerting when the write suppresses the alert', function () {
    Notification::fake();
    $user = User::factory()->create();
    holdAddresses($user, ['jane@example.com' => true]);

    write($user, fn (AccountWrite $write) => $write->record(SecurityEventType::SESSIONS_TERMINATED, alert: false));

    Notification::assertNothingSent();
    $this->assertDatabaseCount('user_security_events', 1);
});

it('rolls back and records nothing when the write fails', function () {
    $user = User::factory()->create();

    try {
        write($user, function (AccountWrite $write) {
            (new Credentials(guard()->userModel()))->store($write->account, new FormType, identifier: null, secret: 'new');
            $write->endSessions();
            $write->record(SecurityEventType::SESSIONS_TERMINATED);

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
    write($user, function (AccountWrite $write) {
        $write->endSessions();
        $write->record(SecurityEventType::SESSIONS_TERMINATED);
    });
    DB::rollBack();

    $this->assertDatabaseCount('user_security_events', 0);
});

describe('the mover\'s own session', function () {
    it('keeps it signed in, on a new session id, when the write ends sessions', function () {
        $user = User::factory()->create();
        guard()->signIn($user);
        $before = session()->getId();

        write($user, fn (AccountWrite $write) => $write->endSessions());

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

        write($user, fn (AccountWrite $write) => $write->endSessions());

        Auth::forgetGuards();
        expect(guard()->user()?->getKey())->toBe($admin->getKey())
            ->and(session()->getId())->toBe($before);
    });

    it('doesn\'t revive a session already on an older epoch', function () {
        $user = User::factory()->create();
        guard()->signIn($user);
        DB::table('users')->where('id', $user->getKey())->increment('credential_epoch');

        write($user, fn (AccountWrite $write) => $write->endSessions());

        Auth::forgetGuards();
        expect(guard()->user())->toBeNull();
    });
});

it('joins a write run inside another write to the same account, moving the epoch once and keeping the mover signed in', function () {
    $user = User::factory()->create();
    guard()->signIn($user);

    write($user, function (AccountWrite $write) {
        $write->endSessions();
        (new EndSessions)->handle(User::query()->findOrFail($write->account->getKey()));
    });

    Auth::forgetGuards();
    expect(epochOf($user))->toBe(1)
        ->and(guard()->user()?->getKey())->toBe($user->getKey());
});

it('runs a write to another account inside a write as a write of its own', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    write($user, function (AccountWrite $write) use ($other) {
        (new EndSessions)->handle($other, operator: 'jane');
    });

    expect(epochOf($user))->toBe(0)
        ->and(epochOf($other))->toBe(1)
        ->and(SecurityEvent::query()->sole()->user_id)->toEqual($other->getKey());
});

it('lets a write that failed be followed by a write of its own', function () {
    $user = User::factory()->create();

    try {
        write($user, fn () => throw new RuntimeException('The write failed.'));
    } catch (RuntimeException) {
        //
    }

    (new EndSessions)->handle($user);

    expect(epochOf($user))->toBe(1);
    $this->assertDatabaseCount('user_security_events', 1);
});

it('records a joined write\'s events once the outer write commits', function () {
    $user = User::factory()->create();

    write($user, function (AccountWrite $write) {
        (new EndSessions)->handle($write->account, operator: 'jane');

        $this->assertDatabaseCount('user_security_events', 0);
    });

    expect(SecurityEvent::query()->sole()->operator)->toBe('jane');
});
