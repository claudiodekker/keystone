<?php

use Carbon\CarbonImmutable;
use ClaudioDekker\Keystone\AccountChange;
use ClaudioDekker\Keystone\AccountChanges;
use ClaudioDekker\Keystone\Actor;
use ClaudioDekker\Keystone\Exceptions\Barred;
use ClaudioDekker\Keystone\Exceptions\SudoRequired;
use ClaudioDekker\Keystone\Exceptions\Superseded;
use ClaudioDekker\Keystone\Flow;
use ClaudioDekker\Keystone\KeystoneGuard;
use ClaudioDekker\Keystone\Methods\EnrolledCredential;
use ClaudioDekker\Keystone\Methods\StoredCredential;
use ClaudioDekker\Keystone\Notifications\SecurityAlert;
use ClaudioDekker\Keystone\PendingOrigin;
use ClaudioDekker\Keystone\PendingSignIn;
use ClaudioDekker\Keystone\PendingStage;
use ClaudioDekker\Keystone\RecoveryCodes;
use ClaudioDekker\Keystone\RememberMe;
use ClaudioDekker\Keystone\RequestContext;
use ClaudioDekker\Keystone\SecurityEvent;
use ClaudioDekker\Keystone\SecurityEventRecorded;
use ClaudioDekker\Keystone\SecurityEventType;
use ClaudioDekker\Keystone\Subnet;
use ClaudioDekker\Keystone\SudoGrant;
use ClaudioDekker\Keystone\SudoInProgress;
use ClaudioDekker\Keystone\Tests\Fixtures\FormType;
use ClaudioDekker\Keystone\Tests\Fixtures\GuardedUser;
use ClaudioDekker\Keystone\Tests\Fixtures\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Monolog\Handler\TestHandler;

function guard(): KeystoneGuard
{
    return Auth::guard('web');
}

function changes(): AccountChanges
{
    return new AccountChanges(guard());
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
    $id = changes()->change($user, fn (AccountChange $change) => $change->addCredential(new FormType, identifier: null, secret: $secret));

    return new StoredCredential($id, identifier: null, secret: $secret, label: null);
}

test('the credential epoch moves only for a change that removes, replaces or ends something', function (Closure $apply, bool $moves, ?string $suspendedAt = null) {
    $this->freezeSecond();
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->getKey())->update(['suspended_at' => $suspendedAt]);
    $credential = storeCredential($user);

    changes()->change($user, fn (AccountChange $change) => $apply($change, $credential));

    $row = DB::table('users')->where('id', $user->getKey())->first();

    expect($row->credential_epoch)->toEqual($moves ? 1 : 0)
        ->and($row->credential_epoch_moved_at)->toEqual($moves ? now()->toDateTimeString() : null);
})->with([
    'nothing' => [fn (AccountChange $change) => null, false],
    'adding a credential' => [fn (AccountChange $change) => $change->addCredential(new FormType, identifier: null, secret: 'new'), false],
    'adding a verified address' => [fn (AccountChange $change) => $change->addVerifiedAddress('new@example.com'), false],
    'a rehash' => [fn (AccountChange $change, StoredCredential $credential) => $change->rehash($credential, type: 'form', secret: 'new-hash'), false],
    'an advance' => [fn (AccountChange $change, StoredCredential $credential) => $change->advance($credential, type: 'form', secret: 'next-step'), false],
    'spending a recovery code' => [fn (AccountChange $change) => $change->spendRecoveryCode('NO-SUCH-CODE', flow: Flow::CHALLENGE, keepLast: false), false],
    'ending sessions' => [fn (AccountChange $change) => $change->endSessions(), true],
    'signing out the other sessions' => [fn (AccountChange $change) => $change->signOutOthers(), true],
    'removing a credential' => [function (AccountChange $change, StoredCredential $credential) {
        $change->addCredential(new FormType, identifier: null, secret: 'spare');

        $change->removeCredential($credential->id);
    }, true],
    'suspending' => [fn (AccountChange $change) => $change->suspend(), true],
    'unsuspending' => [fn (AccountChange $change) => $change->unsuspend(), false, '2026-09-01 12:00:00'],
    'a first set of recovery codes' => [fn (AccountChange $change) => $change->commitRecoveryCodes(['AAAAA-AAAAA'], flow: Flow::ENROLLMENT), false],
    'replacing recovery codes' => [function (AccountChange $change) {
        (new RecoveryCodes($change->account))->replace($change->account->getKey(), ['AAAAA-AAAAA']);

        $change->commitRecoveryCodes(['BBBBB-BBBBB'], flow: Flow::ENROLLMENT);
    }, true],
    'enrolling a credential beside the ones held' => [fn (AccountChange $change) => $change->enroll(new FormType, new EnrolledCredential(identifier: null, secret: 'new'), Flow::SETTINGS, provedAgainst: []), false],
    'enrolling a first credential that replaces its type' => [fn (AccountChange $change) => $change->enroll(new FormType('code'), EnrolledCredential::replacing(identifier: null, secret: 'new'), Flow::SETTINGS, provedAgainst: []), false],
    'enrolling a credential that replaces the one held' => [fn (AccountChange $change, StoredCredential $credential) => $change->enroll(new FormType, EnrolledCredential::replacing(identifier: null, secret: 'new'), Flow::SETTINGS, provedAgainst: [$credential]), true],
    'enrolling again the replacing credential already held' => [fn (AccountChange $change, StoredCredential $credential) => $change->enroll(new FormType, EnrolledCredential::replacing(identifier: null, secret: 'old-hash'), Flow::SETTINGS, provedAgainst: [$credential]), false],
]);

it('records a first set of recovery codes without alerting, and a replacing set with an alert', function (bool $held, bool $alerts) {
    Notification::fake();
    $user = User::factory()->create();
    holdAddresses($user, ['jane@example.com' => true]);

    if ($held) {
        (new RecoveryCodes($user))->replace($user->getKey(), ['AAAAA-AAAAA']);
    }

    changes()->change($user, fn (AccountChange $change) => $change->commitRecoveryCodes(['BBBBB-BBBBB', 'CCCCC-CCCCC'], flow: Flow::ENROLLMENT));

    $this->assertDatabaseHas('user_security_events', ['type' => 'recovery_codes.generated', 'flow' => 'enrollment', 'credential_type' => 'recovery-code']);
    $this->assertDatabaseCount('user_recovery_codes', 2);
    Notification::assertSentOnDemandTimes(SecurityAlert::class, $alerts ? 1 : 0);
})->with([
    'a first set' => [false, false],
    'a replacing set' => [true, true],
]);

it('moves the epoch once however many times the change ends sessions', function () {
    $user = User::factory()->create();

    changes()->change($user, function (AccountChange $change) {
        $change->endSessions();
        $change->endSessions();
    });

    expect(epochOf($user))->toBe(1);
});

it('stamps the account suspended at the time it is suspended', function () {
    $this->freezeSecond();
    $user = User::factory()->create();

    changes()->change($user, fn (AccountChange $change) => $change->suspend());

    expect(DB::table('users')->where('id', $user->getKey())->value('suspended_at'))->toEqual(now()->toDateTimeString());
});

it('clears the stamp to unsuspend the account', function () {
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->getKey())->update(['suspended_at' => now()]);

    changes()->change($user, fn (AccountChange $change) => $change->unsuspend());

    expect(DB::table('users')->where('id', $user->getKey())->value('suspended_at'))->toBeNull();
});

it('applies a rehash only while the credential still holds the secret that was verified', function () {
    $user = User::factory()->create();
    $credential = storeCredential($user);
    $stale = new StoredCredential($credential->id, identifier: null, secret: 'stale-hash', label: null);

    $replaced = changes()->change($user, fn (AccountChange $change) => $change->rehash($stale, type: 'form', secret: 'new-hash'));

    expect($replaced)->toBeFalse()
        ->and(Crypt::decryptString(DB::table('user_credentials')->value('secret')))->toBe('old-hash');
});

it('applies an advance only while the credential still holds the secret that was verified', function () {
    $user = User::factory()->create();
    $credential = storeCredential($user);
    $stale = new StoredCredential($credential->id, identifier: null, secret: 'stale-step', label: null);

    $advanced = changes()->change($user, fn (AccountChange $change) => $change->advance($stale, type: 'form', secret: 'next-step'));

    expect($advanced)->toBeFalse()
        ->and(Crypt::decryptString(DB::table('user_credentials')->value('secret')))->toBe('old-hash');
});

it('hands the change the account read fresh, whatever the caller holds', function () {
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->getKey())->update(['credential_epoch' => 4]);

    $seen = changes()->change($user, fn (AccountChange $change) => $change->account->getRawOriginal('credential_epoch'));

    expect($seen)->toEqual(4);
});

it('moves the epoch on from the one the account holds', function () {
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->getKey())->update(['credential_epoch' => 4]);

    changes()->change($user, fn (AccountChange $change) => $change->endSessions());

    expect(epochOf($user))->toBe(5);
});

it('reads the recipients before the change applies', function (array $addresses, array $recipients) {
    $user = User::factory()->create();
    holdAddresses($user, $addresses);

    $snapshot = changes()->change($user, function (AccountChange $change) {
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

    changes()->change($user, function (AccountChange $change) {
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

    changes()->change($user, function (AccountChange $change) {
        DB::table('user_emails')->delete();

        $change->record(SecurityEventType::SESSIONS_TERMINATED, actor: Actor::OPERATOR);
    });

    Notification::assertSentOnDemand(SecurityAlert::class, fn (SecurityAlert $alert, array $channels, object $notifiable) => $notifiable->routes['mail'] === 'jane@example.com');
});

it('records the event without alerting when the change suppresses the alert', function () {
    Notification::fake();
    $user = User::factory()->create();
    holdAddresses($user, ['jane@example.com' => true]);

    changes()->change($user, fn (AccountChange $change) => $change->record(SecurityEventType::SESSIONS_TERMINATED, actor: Actor::OPERATOR, alert: false));

    Notification::assertNothingSent();
    $this->assertDatabaseCount('user_security_events', 1);
});

it('rolls back and records nothing when the change fails', function () {
    $user = User::factory()->create();

    try {
        changes()->change($user, function (AccountChange $change) {
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
    changes()->change($user, function (AccountChange $change) {
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

        changes()->change($user, fn (AccountChange $change) => $change->endSessions());

        Auth::forgetGuards();
        expect(guard()->user()?->getKey())->toBe($user->getKey())
            ->and(session()->getId())->not->toBe($before);
    });

    it('leaves its session id alone when the epoch doesn\'t move', function () {
        $user = User::factory()->create();
        guard()->signIn($user);
        $before = session()->getId();

        changes()->change($user, fn (AccountChange $change) => $change->addCredential(new FormType, identifier: null, secret: 'new'));

        expect(session()->getId())->toBe($before);
    });

    it('leaves a session signed in as another account on its id after an enrollment', function () {
        $admin = User::factory()->create();
        $user = User::factory()->create();
        guard()->signIn($admin);
        $before = session()->getId();

        changes()->change($user, fn (AccountChange $change) => $change->enroll(new FormType, new EnrolledCredential(identifier: null, secret: 'new'), Flow::SETTINGS, provedAgainst: []));

        expect(session()->getId())->toBe($before);
    });

    it('doesn\'t carry a session signed in as another account', function () {
        $admin = User::factory()->create();
        $user = User::factory()->create();
        guard()->signIn($admin);
        $before = session()->getId();

        changes()->change($user, fn (AccountChange $change) => $change->endSessions());

        Auth::forgetGuards();
        expect(guard()->user()?->getKey())->toBe($admin->getKey())
            ->and(session()->getId())->toBe($before);
    });

    it('doesn\'t revive a session already on an older epoch', function () {
        $user = User::factory()->create();
        guard()->signIn($user);
        DB::table('users')->where('id', $user->getKey())->increment('credential_epoch');

        changes()->change($user, fn (AccountChange $change) => $change->endSessions());

        Auth::forgetGuards();
        expect(guard()->user())->toBeNull();
    });
});

describe('ending every account\'s sessions', function () {
    it('moves every account\'s epoch on by one', function () {
        $this->freezeSecond();
        $jane = User::factory()->create();
        $john = User::factory()->create();
        DB::table('users')->where('id', $john->getKey())->update(['credential_epoch' => 4]);

        changes()->endEverySession();

        expect(epochOf($jane))->toBe(1)
            ->and(epochOf($john))->toBe(5)
            ->and(DB::table('users')->pluck('credential_epoch_moved_at')->unique()->all())->toBe([now()->toDateTimeString()]);
    });

    it('moves no epoch when forgetting the known devices fails', function () {
        $user = User::factory()->create();
        DB::connection()->beforeExecuting(function (string $query) {
            throw_if(str_starts_with($query, 'delete') && str_contains($query, 'user_known_devices'), RuntimeException::class, 'The devices could not be forgotten.');
        });

        expect(fn () => changes()->endEverySession())->toThrow(RuntimeException::class, 'The devices could not be forgotten.');

        expect(epochOf($user))->toBe(0);
    });

    it('ends the mover\'s own session too', function () {
        $user = User::factory()->create();
        guard()->signIn($user);

        changes()->endEverySession();

        Auth::forgetGuards();
        expect(guard()->user())->toBeNull();
    });

    it('logs one event about nobody, naming the operator', function () {
        config([
            'logging.channels.keystone-test' => ['driver' => 'monolog', 'handler' => TestHandler::class],
            'keystone.log_channel' => 'keystone-test',
        ]);
        User::factory()->count(2)->create();

        changes()->endEverySession(operator: 'jane');

        $records = Log::channel('keystone-test')->getLogger()->getHandlers()[0]->getRecords();

        expect($records)->toHaveCount(1)
            ->and($records[0]->context)->toMatchArray(['type' => 'sessions.terminated', 'user_id' => null, 'actor' => 'operator', 'operator' => 'jane', 'reason' => 'keystone.every_account']);
        $this->assertDatabaseCount('user_security_events', 0);
    });

    it('records nothing when a transaction around it rolls back', function () {
        Event::fake([SecurityEventRecorded::class]);
        User::factory()->create();

        DB::beginTransaction();
        changes()->endEverySession();
        DB::rollBack();

        Event::assertNotDispatched(SecurityEventRecorded::class);
    });
});

it('writes to the account whatever the app\'s model guards or its observers refuse', function () {
    $user = GuardedUser::query()->findOrFail(User::factory()->create()->getKey());
    GuardedUser::updating(fn () => false);

    changes()->change($user, fn (AccountChange $change) => $change->suspend());

    $row = DB::table('users')->where('id', $user->getKey())->first();

    expect($row->suspended_at)->not->toBeNull()
        ->and($row->credential_epoch)->toEqual(1);
});

function pendingSignIn(User $user): PendingSignIn
{
    return new PendingSignIn($user, 'form', PendingOrigin::LOGIN, PendingStage::CHALLENGE, '/', CarbonImmutable::now(), epochOf($user), false, null, RememberMe::NOT_ASKED);
}

function sudoInProgress(): SudoInProgress
{
    return new SudoInProgress('/', CarbonImmutable::now(), null);
}

function sudoGrant(): SudoGrant
{
    return new SudoGrant(CarbonImmutable::now(), 300, new Subnet('127.0.0.0/24'));
}

function holdLiveSudo(): void
{
    app()->instance(RequestContext::class, new RequestContext(ipAddress: '127.0.0.1'));
    guard()->beginSudo('/');
    guard()->grantSudo(guard()->context()->subnet());
}

describe('the session writing the change', function () {
    it('refuses a writer the account bars or the session may no longer write for, applying and recording nothing', function (string $refusal, Closure $writer, string $exception) {
        $user = User::factory()->create();
        holdLiveSudo();
        $named = $writer($user);
        match ($refusal) {
            'barred' => DB::table('users')->where('id', $user->getKey())->update(['suspended_at' => now()]),
            'superseded' => DB::table('users')->where('id', $user->getKey())->increment('credential_epoch'),
            'sudo ended' => guard()->endSudo(),
        };
        Event::fake([SecurityEventRecorded::class]);

        expect(fn () => changes()->change($user, fn (AccountChange $change) => $change->addCredential(new FormType, identifier: null, secret: 'new'), $named))->toThrow($exception);

        $this->assertDatabaseCount('user_credentials', 0);
        $this->assertDatabaseCount('user_security_events', 0);
        Event::assertNotDispatched(SecurityEventRecorded::class);
    })->with([
        'a pending sign-in of a barred account' => ['barred', fn (User $user) => pendingSignIn($user), Barred::class],
        'a pending sign-in off the epoch' => ['superseded', fn (User $user) => pendingSignIn($user), Superseded::class],
        'a sudo grant of a barred account' => ['barred', fn () => sudoGrant(), Barred::class],
        'a sudo grant that ended' => ['sudo ended', fn () => sudoGrant(), SudoRequired::class],
        'a sudo in progress of a barred account' => ['barred', fn () => sudoInProgress(), Barred::class],
    ]);

    it('lets a writer that may write through', function (Closure $writer) {
        $user = User::factory()->create();
        holdLiveSudo();

        changes()->change($user, fn (AccountChange $change) => $change->addCredential(new FormType, identifier: null, secret: 'new'), $writer($user));

        $this->assertDatabaseCount('user_credentials', 1);
    })->with([
        'a pending sign-in on the epoch' => [fn (User $user) => pendingSignIn($user)],
        'a sudo grant that is live' => [fn () => sudoGrant()],
        'a sudo in progress of an account in good standing' => [fn () => sudoInProgress()],
    ]);

    it('lets a sudo in progress write whatever the epoch or the grant', function () {
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->getKey())->increment('credential_epoch');

        changes()->change($user, fn (AccountChange $change) => $change->addCredential(new FormType, identifier: null, secret: 'new'), sudoInProgress());

        $this->assertDatabaseCount('user_credentials', 1);
    });

    it('lets a live sudo grant write whatever the epoch', function () {
        $user = User::factory()->create();
        holdLiveSudo();
        DB::table('users')->where('id', $user->getKey())->increment('credential_epoch');

        changes()->change($user, fn (AccountChange $change) => $change->addCredential(new FormType, identifier: null, secret: 'new'), sudoGrant());

        $this->assertDatabaseCount('user_credentials', 1);
    });
});
