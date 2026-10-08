<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\Jobs\SuspendAccount;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\EnrolledCredential;
use ClaudioDekker\Keystone\Methods\Initiation;
use ClaudioDekker\Keystone\Methods\Proof;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Notifications\SecurityAlert;
use ClaudioDekker\Keystone\SecurityEvent;
use ClaudioDekker\Keystone\SecurityEventType;
use ClaudioDekker\Keystone\Tests\Fixtures\DrawingType;
use ClaudioDekker\Keystone\Tests\Fixtures\FormType;
use ClaudioDekker\Keystone\Tests\Fixtures\FormTypeSupport;
use ClaudioDekker\Keystone\Tests\Fixtures\RogueType;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;

pest()->extend(AppTestCase::class);

beforeEach(function () {
    $this->withoutMandates();
});

function startSettingsEnrollment(AppTestCase $test, string $type = 'code'): mixed
{
    $test->get(route('security.enroll', ['type' => $type]));

    return Keystone::guard()->slots()->get($type, Surface::ENROLLMENT->value)['ceremony'] ?? null;
}

function whileTheAnswerIsChecked(Closure $step): void
{
    app()->make(CredentialTypes::class)->register(new RogueType(function () use ($step) {
        $step();

        return Proof::enrolled(new EnrolledCredential(identifier: null, secret: 'rogue-secret'));
    }, surfaces: ['challenge', 'enrollment']));
}

describe('the enrollment step', function () {
    it('starts the type\'s ceremony and shows what it made', function () {
        $account = $this->signInAccount(new FormTypeSupport);

        $response = $this->get(route('security.enroll', ['type' => 'code']));

        $ceremony = Keystone::guard()->slots()->get('code', Surface::ENROLLMENT->value);
        $response->assertOk()->assertExactJson([
            'type' => 'code',
            'shape' => 'form',
            'ceremony' => ['code' => $ceremony['ceremony'], 'account' => 'jane@example.com'],
            'status' => null,
        ]);
        $this->assertAuthenticatedAs($account);
    });

    it('shows the same ceremony on a second visit', function () {
        $this->signInAccount(new FormTypeSupport);

        $first = $this->get(route('security.enroll', ['type' => 'code']))->json('ceremony.code');
        $second = $this->get(route('security.enroll', ['type' => 'code']))->json('ceremony.code');

        expect($second)->toBe($first);
    });

    it('shows what a type draws from its ceremony\'s page on every visit, keeping none of it in the session', function () {
        $this->app->make(CredentialTypes::class)->register(new DrawingType);
        $this->signInAccount(new FormTypeSupport);

        $first = $this->get(route('security.enroll', ['type' => 'drawn']))->json('ceremony');
        $second = $this->get(route('security.enroll', ['type' => 'drawn']))->json('ceremony');

        $kept = Keystone::guard()->slots()->get('drawn', Surface::ENROLLMENT->value);
        expect($first['drawing'])->toBe(str_repeat($first['code'], 1024))
            ->and($second)->toBe($first)
            ->and($kept['page'])->toBe(['code' => $first['code'], 'account' => 'jane@example.com']);
    });

    it('puts the hardening floor on the step, so the browser stores none of it', function () {
        $this->signInAccount(new FormTypeSupport);

        $response = $this->get(route('security.enroll', ['type' => 'code']));

        $this->assertHardeningFloor($response);
    });

    it('sends a type that isn\'t listed on enrollment to the security page, opening no ceremony', function (string $type, ?array $methods) {
        config(['keystone.methods' => $methods]);
        $this->signInAccount(new FormTypeSupport);

        $this->get(route('security.enroll', ['type' => $type]))->assertRedirectToRoute('security');
        $this->post(route('security.enroll.submit', ['type' => $type]), ['secret' => 'anything'])->assertRedirectToRoute('security');

        expect(Keystone::guard()->slots()->get($type, Surface::ENROLLMENT->value))->toBeNull();
        $this->assertDatabaseCount('user_credentials', 1);
    })->with([
        'a type that only serves sign-in' => ['form', null],
        'a type keystone.methods keeps off enrollment' => ['code', ['form', 'code' => ['challenge']]],
        'a type nothing registered' => ['passkey', null],
    ]);

    it('asks for sudo first, and comes back to the type\'s step', function () {
        $this->signInAccount(new FormTypeSupport);
        $this->delete(route('sudo.end'));

        $response = $this->get(route('security.enroll', ['type' => 'code']));

        $response->assertRedirectToRoute('sudo');
        expect(Keystone::guard()->sudoInProgress()->intendedUrl)->toBe(route('security.enroll', ['type' => 'code'], absolute: false))
            ->and(Keystone::guard()->slots()->get('code', Surface::ENROLLMENT->value))->toBeNull();
    });

    it('sends a guest to sign in', function () {
        $this->get(route('security.enroll', ['type' => 'code']))->assertRedirectToRoute('login');
    });

    it('takes the start limit', function () {
        $this->signInAccount(new FormTypeSupport);

        foreach (range(1, config()->integer('keystone.rate_limits.requests_per_minute.start')) as $ignored) {
            $this->get(route('security.enroll', ['type' => 'code']))->assertOk();
        }

        $this->get(route('security.enroll', ['type' => 'code']))->assertTooManyRequests();
    });

    it('reports a ceremony that fails to start and sends the user back to the security page', function () {
        Exceptions::fake();
        $this->app->make(CredentialTypes::class)->register(new class(name: 'broken', surfaces: ['challenge', 'enrollment']) extends FormType
        {
            public function initiate(Surface $surface, string $accountName): Initiation
            {
                throw new RuntimeException('Authenticator offline.');
            }
        });
        $this->signInAccount(new FormTypeSupport);

        $response = $this->get(route('security.enroll', ['type' => 'broken']));

        $response->assertRedirectToRoute('security')->assertSessionHasErrors(['broken' => __('keystone::messages.enrollment_failed')]);
        Exceptions::assertReported(RuntimeException::class);
        expect(Keystone::guard()->slots()->get('broken', Surface::ENROLLMENT->value))->toBeNull();
    });
});

describe('the ceremony and the sudo it was opened under', function () {
    it('ends the ceremony when the grant ends, though the session lives on', function () {
        $this->freezeSecond();
        $account = $this->signInAccount(new FormTypeSupport);
        $this->travel(5)->minutes();
        startSettingsEnrollment($this);
        $this->travel(599)->seconds();
        expect(Keystone::guard()->slots()->get('code', Surface::ENROLLMENT->value))->not->toBeNull();

        $this->travel(1)->seconds();

        expect(Keystone::guard()->slots()->get('code', Surface::ENROLLMENT->value))->toBeNull();
        $this->assertAuthenticatedAs($account);
    });

    it('shows a new ceremony under the next grant, never the one the last grant opened', function (Closure $lose) {
        $this->freezeSecond();
        $this->signInAccount(new FormTypeSupport);
        $first = startSettingsEnrollment($this);
        $lose($this);
        $this->get(route('security.enroll', ['type' => 'code']))->assertRedirectToRoute('sudo');
        $this->post(route('sudo.submit', ['type' => 'form']), (new FormTypeSupport)->validProof(Surface::SIGN_IN));

        $response = $this->post(route('security.enroll.submit', ['type' => 'code']), ['secret' => $first]);

        $response->assertRedirectToRoute('security.enroll', ['type' => 'code']);
        $this->assertDatabaseMissing('user_credentials', ['type' => 'code']);
        $second = $this->get(route('security.enroll', ['type' => 'code']));
        $second->assertJsonPath('status', __('keystone::messages.status.enrollment-expired'));
        expect($second->json('ceremony.code'))->not->toBe($first);
    })->with([
        'the grant ran out' => [fn (AppTestCase $test) => $test->outliveSudo()],
        'the user ended sudo' => [fn (AppTestCase $test) => $test->delete(route('sudo.end'))],
    ]);
});

describe('the answer', function () {
    it('stores the credential, records credential.added in the settings flow, alerts the owner and says so on the security page', function () {
        $account = $this->signInAccount(new FormTypeSupport);
        $ceremony = startSettingsEnrollment($this);
        Notification::fake();

        $response = $this->post(route('security.enroll.submit', ['type' => 'code']), ['secret' => $ceremony]);

        $response->assertRedirectToRoute('security');
        $stored = DB::table('user_credentials')->where('user_id', $account->getKey())->where('type', 'code')->sole();
        $this->assertDatabaseHas('user_security_events', [
            'type' => 'credential.added',
            'user_id' => $account->getKey(),
            'actor' => 'user',
            'flow' => 'settings',
            'credential_type' => 'code',
            'credential_id' => $stored->id,
        ]);
        Notification::assertSentOnDemandTimes(SecurityAlert::class, 1);
        Notification::assertSentOnDemand(SecurityAlert::class, fn (SecurityAlert $alert) => $alert->type === SecurityEventType::CREDENTIAL_ADDED);
        $this->get(route('security'))->assertJsonPath('status', __('keystone::messages.status.enrolled'));
    });

    it('moves no epoch for a credential it adds, rotates the session id, keeps its sudo and closes the ceremony', function () {
        $this->freezeSecond();
        $account = $this->signInAccount(new FormTypeSupport);
        $ceremony = startSettingsEnrollment($this);
        $sessionId = session()->getId();

        $this->post(route('security.enroll.submit', ['type' => 'code']), ['secret' => $ceremony]);

        expect(DB::table('users')->where('id', $account->getKey())->value('credential_epoch'))->toEqual(0)
            ->and(session()->getId())->not->toBe($sessionId)
            ->and(Keystone::guard()->slots()->get('code', Surface::ENROLLMENT->value))->toBeNull();
        $this->get(route('security'))->assertOk()->assertJsonPath('sudoEndsAt', now()->addMinutes(15)->toIso8601String());
        $this->assertAuthenticatedAs($account);
    });

    it('adds beside the credentials of the type the account holds, unless the type replaces', function () {
        $account = $this->signInAccount(new FormTypeSupport);
        $held = DB::table('user_credentials')->insertGetId(['user_id' => $account->getKey(), 'type' => 'code', 'secret' => Crypt::encryptString('held')]);
        $ceremony = startSettingsEnrollment($this);

        $this->post(route('security.enroll.submit', ['type' => 'code']), ['secret' => $ceremony])->assertRedirectToRoute('security');

        $this->assertDatabaseHas('user_credentials', ['id' => $held]);
        $this->assertDatabaseHas('users', ['id' => $account->getKey(), 'credential_epoch' => 0]);
        expect(DB::table('user_credentials')->where('type', 'code')->count())->toBe(2);
    });

    it('replaces every credential of a type that says so, moves the epoch and keeps this session signed in with its sudo', function () {
        $this->freezeSecond();
        $this->app->make(CredentialTypes::class)->register(new FormType(name: 'single', surfaces: ['challenge', 'enrollment'], replacesExisting: true));
        $account = $this->signInAccount(new FormTypeSupport);
        DB::table('user_credentials')->insert([
            ['user_id' => $account->getKey(), 'type' => 'single', 'secret' => Crypt::encryptString('held'), 'disabled_at' => null],
            ['user_id' => $account->getKey(), 'type' => 'single', 'secret' => Crypt::encryptString('disabled'), 'disabled_at' => now()],
        ]);
        $ceremony = startSettingsEnrollment($this, 'single');
        $sessionId = session()->getId();

        $response = $this->post(route('security.enroll.submit', ['type' => 'single']), ['secret' => $ceremony]);

        $response->assertRedirectToRoute('security');
        $left = DB::table('user_credentials')->where('user_id', $account->getKey())->where('type', 'single')->sole();
        expect($left->disabled_at)->toBeNull()
            ->and(DB::table('users')->where('id', $account->getKey())->value('credential_epoch'))->toEqual(1)
            ->and(session()->getId())->not->toBe($sessionId)
            ->and(SecurityEvent::query()->whereIn('type', ['credential.added', 'credential.removed'])->pluck('type')->all())->toBe([SecurityEventType::CREDENTIAL_ADDED]);
        $this->get(route('security'))->assertOk()
            ->assertJsonPath('sudoEndsAt', now()->addMinutes(15)->toIso8601String())
            ->assertJsonPath('status', __('keystone::messages.status.enrolled'));
        $this->assertAuthenticatedAs($account);
    });

    it('refuses a wrong answer, stores nothing, keeps the ceremony and records the rejection in the settings flow', function () {
        $account = $this->signInAccount(new FormTypeSupport);
        $ceremony = startSettingsEnrollment($this);

        $response = $this->post(route('security.enroll.submit', ['type' => 'code']), ['secret' => 'wrong '.$ceremony]);

        $response->assertRedirectToRoute('security.enroll', ['type' => 'code'])
            ->assertSessionHasErrors(['code' => __('keystone::messages.invalid_credential')]);
        $this->assertDatabaseMissing('user_credentials', ['type' => 'code']);
        $this->assertDatabaseHas('user_security_events', ['type' => 'proof.rejected', 'user_id' => $account->getKey(), 'flow' => 'settings', 'credential_type' => 'code', 'reason' => 'code.mismatch']);
        expect($this->get(route('security.enroll', ['type' => 'code']))->json('ceremony.code'))->toBe($ceremony)
            ->and(session()->getOldInput())->toBe([]);
    });

    it('waits out the timing floor on a wrong answer', function () {
        $this->signInAccount(new FormTypeSupport);
        $ceremony = startSettingsEnrollment($this);

        $response = $this->assertWaitsOutTimingFloor(fn () => $this->post(route('security.enroll.submit', ['type' => 'code']), ['secret' => 'wrong '.$ceremony]));

        $response->assertRedirectToRoute('security.enroll', ['type' => 'code']);
    });

    it('counts wrong answers in the settings flow, apart from the challenge and sudo', function () {
        config(['keystone.rate_limits.failed_attempts_per_hour' => 1]);
        $account = $this->signInAccount(new FormTypeSupport);
        $ceremony = startSettingsEnrollment($this);
        $this->post(route('security.enroll.submit', ['type' => 'code']), ['secret' => 'wrong '.$ceremony]);

        $response = $this->post(route('security.enroll.submit', ['type' => 'code']), ['secret' => $ceremony]);

        $response->assertTooManyRequests();
        $this->assertDatabaseMissing('user_credentials', ['type' => 'code']);
        $this->assertDatabaseHas('user_security_events', ['type' => 'limit.tripped', 'user_id' => $account->getKey(), 'flow' => 'settings', 'credential_type' => 'code']);
        $this->delete(route('sudo.end'));
        $this->get(route('security.enroll', ['type' => 'code']));
        $this->post(route('sudo.submit', ['type' => 'form']), (new FormTypeSupport)->validProof(Surface::SIGN_IN))->assertRedirect(route('security.enroll', ['type' => 'code'], absolute: false));
    });

    it('gives a right answer\'s attempt back', function () {
        config(['keystone.rate_limits.failed_attempts_per_hour' => 1]);
        $this->signInAccount(new FormTypeSupport);

        foreach (range(1, 3) as $ignored) {
            $ceremony = startSettingsEnrollment($this);

            $this->post(route('security.enroll.submit', ['type' => 'code']), ['secret' => $ceremony])->assertRedirectToRoute('security');
        }

        expect(DB::table('user_credentials')->where('type', 'code')->count())->toBe(3);
    });

    it('returns input the type\'s rules refuse to the step with its errors, taking no failed attempt', function () {
        config(['keystone.rate_limits.failed_attempts_per_hour' => 1]);
        $this->signInAccount(new FormTypeSupport);
        $ceremony = startSettingsEnrollment($this);

        $response = $this->post(route('security.enroll.submit', ['type' => 'code']), []);

        $response->assertRedirectToRoute('security.enroll', ['type' => 'code'])->assertSessionHasErrors(['secret']);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'proof.rejected']);
        $this->post(route('security.enroll.submit', ['type' => 'code']), ['secret' => $ceremony])->assertRedirectToRoute('security');
    });

    it('sends an answer with no live ceremony back to the type\'s step with enrollment-expired, before it looks at the input', function (array $input) {
        $this->signInAccount(new FormTypeSupport);

        $response = $this->post(route('security.enroll.submit', ['type' => 'code']), $input);

        $response->assertRedirectToRoute('security.enroll', ['type' => 'code'])->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('user_credentials', ['type' => 'code']);
        $this->get(route('security.enroll', ['type' => 'code']))->assertJsonPath('status', __('keystone::messages.status.enrollment-expired'));
    })->with([
        'an answer' => [['secret' => 'anything']],
        'no input at all' => [[]],
    ]);

    it('asks for sudo first, storing nothing and keeping no answer to replay', function () {
        $this->signInAccount(new FormTypeSupport);
        $ceremony = startSettingsEnrollment($this);
        $this->delete(route('sudo.end'));

        $response = $this->post(route('security.enroll.submit', ['type' => 'code']), ['secret' => $ceremony]);

        $response->assertRedirectToRoute('sudo');
        $this->assertDatabaseMissing('user_credentials', ['type' => 'code']);
    });

    it('sends a guest to sign in', function () {
        $this->post(route('security.enroll.submit', ['type' => 'code']), ['secret' => 'anything'])->assertRedirectToRoute('login');
    });

    it('takes the submit limit', function () {
        $this->signInAccount(new FormTypeSupport);
        $this->travel(1)->minute();

        foreach (range(1, config()->integer('keystone.rate_limits.requests_per_minute.submit')) as $ignored) {
            $this->post(route('security.enroll.submit', ['type' => 'code']))->assertRedirectToRoute('security.enroll', ['type' => 'code']);
        }

        $this->post(route('security.enroll.submit', ['type' => 'code']))->assertTooManyRequests();
    });
});

describe('what changed between the gate and the write', function () {
    it('stores nothing when the sudo grant ran out while the answer was checked, and asks for sudo again without counting a wrong answer', function () {
        config(['keystone.rate_limits.failed_attempts_per_hour' => 1]);
        $this->freezeSecond();
        whileTheAnswerIsChecked(fn () => $this->outliveSudo());
        $this->signInAccount(new FormTypeSupport);
        startSettingsEnrollment($this, 'rogue');

        $response = $this->from(route('security.enroll', ['type' => 'rogue']))->post(route('security.enroll.submit', ['type' => 'rogue']));

        $response->assertRedirectToRoute('sudo');
        $this->assertDatabaseMissing('user_credentials', ['type' => 'rogue']);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'credential.added']);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'proof.rejected']);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'limit.tripped']);
        expect(Keystone::guard()->sudoInProgress()->intendedUrl)->toBe(route('security.enroll', ['type' => 'rogue'], absolute: false));
    });

    it('stores nothing for a type taken off enrollment while the answer was checked', function () {
        whileTheAnswerIsChecked(fn () => config(['keystone.methods' => ['form', 'rogue' => ['challenge']]]));
        $account = $this->signInAccount(new FormTypeSupport);
        startSettingsEnrollment($this, 'rogue');

        $response = $this->post(route('security.enroll.submit', ['type' => 'rogue']));

        $response->assertRedirectToRoute('security.enroll', ['type' => 'rogue'])->assertSessionHasErrors(['rogue' => __('keystone::messages.invalid_credential')]);
        $this->assertDatabaseMissing('user_credentials', ['type' => 'rogue']);
        $this->assertDatabaseHas('user_security_events', ['type' => 'proof.rejected', 'user_id' => $account->getKey(), 'flow' => 'settings', 'reason' => 'keystone.unoffered']);
    });

    it('stores nothing for an account suspended while the answer was checked', function () {
        $account = $this->createAccount();
        whileTheAnswerIsChecked(fn () => SuspendAccount::dispatchSync($account));
        $this->arrangeCredential($account, new FormTypeSupport, Surface::SIGN_IN);
        $this->submitSignIn(new FormTypeSupport, 'jane@example.com', (new FormTypeSupport)->validProof(Surface::SIGN_IN));
        startSettingsEnrollment($this, 'rogue');

        $this->post(route('security.enroll.submit', ['type' => 'rogue']));

        $this->assertDatabaseMissing('user_credentials', ['type' => 'rogue']);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'credential.added']);
        $this->assertDatabaseHas('user_security_events', ['type' => 'proof.rejected', 'user_id' => $account->getKey(), 'flow' => 'settings', 'reason' => 'keystone.barred']);
    });

    it('stores nothing and records nothing when the write fails, and stores one credential on the next answer', function () {
        Exceptions::fake();
        $account = $this->signInAccount(new FormTypeSupport);
        $ceremony = startSettingsEnrollment($this);
        $fails = true;
        DB::connection()->beforeExecuting(function (string $query) use (&$fails) {
            if ($fails && str_starts_with($query, 'insert') && str_contains($query, 'user_credentials')) {
                throw new RuntimeException('The write failed.');
            }
        });

        $this->post(route('security.enroll.submit', ['type' => 'code']), ['secret' => $ceremony])->assertServerError();

        $this->assertDatabaseMissing('user_credentials', ['type' => 'code']);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'credential.added']);
        $fails = false;

        $this->post(route('security.enroll.submit', ['type' => 'code']), ['secret' => $ceremony])->assertRedirectToRoute('security');

        expect(DB::table('user_credentials')->where('user_id', $account->getKey())->where('type', 'code')->count())->toBe(1);
    });
});

describe('the same answer sent twice at once', function () {
    it('stores a first credential of a replacing type once: one row, no epoch move, one event and one alert', function () {
        $this->app->make(CredentialTypes::class)->register(new FormType(name: 'single', surfaces: ['challenge', 'enrollment'], replacesExisting: true));
        $account = $this->signInAccount(new FormTypeSupport);
        $ceremony = startSettingsEnrollment($this, 'single');
        $readByBoth = Keystone::guard()->slots()->get('single', Surface::ENROLLMENT->value);
        Notification::fake();
        $this->post(route('security.enroll.submit', ['type' => 'single']), ['secret' => $ceremony])->assertRedirectToRoute('security');
        Keystone::guard()->slots()->put('single', Surface::ENROLLMENT->value, $readByBoth, capSeconds: 900);
        session()->save();
        $sessionId = session()->getId();

        $response = $this->post(route('security.enroll.submit', ['type' => 'single']), ['secret' => $ceremony]);

        $response->assertRedirectToRoute('security');
        $this->get(route('security'))->assertJsonPath('status', __('keystone::messages.status.enrolled'));
        $this->assertDatabaseHas('users', ['id' => $account->getKey(), 'credential_epoch' => 0]);
        expect(DB::table('user_credentials')->where('user_id', $account->getKey())->where('type', 'single')->count())->toBe(1)
            ->and(SecurityEvent::query()->where('type', 'credential.added')->count())->toBe(1)
            ->and(SecurityEvent::query()->where('type', 'proof.rejected')->count())->toBe(0)
            ->and(session()->getId())->toBe($sessionId)
            ->and(Keystone::guard()->slots()->get('single', Surface::ENROLLMENT->value))->toBeNull();
        Notification::assertSentOnDemandTimes(SecurityAlert::class, 1);
    });
});

describe('a replacement whose write fails', function () {
    it('keeps the credential the account holds, records nothing and moves no epoch', function () {
        Exceptions::fake();
        $this->app->make(CredentialTypes::class)->register(new FormType(name: 'single', surfaces: ['challenge', 'enrollment'], replacesExisting: true));
        $account = $this->signInAccount(new FormTypeSupport);
        $held = DB::table('user_credentials')->insertGetId(['user_id' => $account->getKey(), 'type' => 'single', 'secret' => Crypt::encryptString('held')]);
        $ceremony = startSettingsEnrollment($this, 'single');
        DB::connection()->beforeExecuting(function (string $query) {
            if (str_starts_with($query, 'insert') && str_contains($query, 'user_credentials')) {
                throw new RuntimeException('The write failed.');
            }
        });

        $this->post(route('security.enroll.submit', ['type' => 'single']), ['secret' => $ceremony])->assertServerError();

        $this->assertDatabaseHas('user_credentials', ['id' => $held]);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'credential.added']);
        $this->assertDatabaseHas('users', ['id' => $account->getKey(), 'credential_epoch' => 0]);
        expect(DB::table('user_credentials')->where('type', 'single')->count())->toBe(1);
        $this->assertAuthenticatedAs($account);
    });
});

describe('cancelling', function () {
    it('closes the ceremony and sends the user to the security page, so the next visit starts another', function () {
        $this->signInAccount(new FormTypeSupport);
        $first = startSettingsEnrollment($this);

        $response = $this->delete(route('security.enroll.cancel', ['type' => 'code']));

        $response->assertRedirectToRoute('security');
        expect(Keystone::guard()->slots()->get('code', Surface::ENROLLMENT->value))->toBeNull()
            ->and(startSettingsEnrollment($this))->not->toBe($first);
    });

    it('needs no sudo', function () {
        $account = $this->signInAccount(new FormTypeSupport);
        $this->delete(route('sudo.end'));

        $response = $this->delete(route('security.enroll.cancel', ['type' => 'code']));

        $response->assertRedirectToRoute('security');
        $this->assertAuthenticatedAs($account);
        expect(Keystone::guard()->sudoInProgress())->toBeNull();
    });

    it('sends a guest to sign in', function () {
        $this->delete(route('security.enroll.cancel', ['type' => 'code']))->assertRedirectToRoute('login');
    });

    it('takes the change limit', function () {
        $this->signInAccount(new FormTypeSupport);

        foreach (range(1, config()->integer('keystone.rate_limits.requests_per_minute.change')) as $ignored) {
            $this->delete(route('security.enroll.cancel', ['type' => 'code']))->assertRedirectToRoute('security');
        }

        $this->delete(route('security.enroll.cancel', ['type' => 'code']))->assertTooManyRequests();
    });
});

describe('the security page', function () {
    it('marks the types listed on enrollment as ones the user can set up', function () {
        $this->signInAccount(new FormTypeSupport);

        $response = $this->get(route('security'));

        $response->assertOk()
            ->assertJsonPath('types.0.type', 'form')
            ->assertJsonPath('types.0.enrollable', false)
            ->assertJsonPath('types.1.type', 'code')
            ->assertJsonPath('types.1.enrollable', true);
    });

    it('marks a type keystone.methods keeps off enrollment as one the user can\'t set up', function () {
        config(['keystone.methods' => ['form', 'code' => ['challenge']]]);
        $this->signInAccount(new FormTypeSupport);

        $this->get(route('security'))->assertJsonPath('types.1.type', 'code')->assertJsonPath('types.1.enrollable', false);
    });
});
