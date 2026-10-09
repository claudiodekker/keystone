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
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;

pest()->extend(AppTestCase::class);

beforeEach(function () {
    $this->withoutMandates();
});

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
            'held' => [],
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

    it('reports the account\'s usable credentials of the type, and that each can be removed while another way to sign in remains', function () {
        $account = $this->signInAccount(new FormTypeSupport);
        $first = DB::table('user_credentials')->insertGetId(['user_id' => $account->getKey(), 'type' => 'code', 'label' => 'Phone', 'secret' => Crypt::encryptString('first')]);
        $second = DB::table('user_credentials')->insertGetId(['user_id' => $account->getKey(), 'type' => 'code', 'secret' => Crypt::encryptString('second')]);
        DB::table('user_credentials')->insert(['user_id' => $account->getKey(), 'type' => 'code', 'secret' => Crypt::encryptString('disabled'), 'disabled_at' => now()]);
        DB::table('user_credentials')->insert(['user_id' => $account->getKey(), 'type' => 'form', 'secret' => Crypt::encryptString('another type')]);

        $response = $this->get(route('security.enroll', ['type' => 'code']));

        $response->assertOk()->assertJsonPath('held', [
            ['id' => $first, 'label' => 'Phone', 'removable' => true],
            ['id' => $second, 'label' => null, 'removable' => true],
        ]);
    });

    it('reports the only usable way to sign in as not removable', function () {
        $this->app->make(CredentialTypes::class)->register(new FormType(name: 'both', surfaces: ['sign-in', 'enrollment']));
        $account = $this->signInAccount(new FormTypeSupport);
        $held = DB::table('user_credentials')->where('user_id', $account->getKey())->value('id');
        DB::table('user_credentials')->where('id', $held)->update(['type' => 'both']);
        DB::table('user_credentials')->insert(['user_id' => $account->getKey(), 'type' => 'form', 'secret' => Crypt::encryptString('disabled'), 'disabled_at' => now()]);

        $response = $this->get(route('security.enroll', ['type' => 'both']));

        $response->assertOk()->assertJsonPath('held', [['id' => $held, 'label' => null, 'removable' => false]]);
    });

    it('reports the only second factor as not removable while the app requires one', function () {
        $account = $this->signInAccount(new FormTypeSupport);
        $held = DB::table('user_credentials')->insertGetId(['user_id' => $account->getKey(), 'type' => 'code', 'secret' => Crypt::encryptString('held')]);
        config(['keystone.require_second_factor' => true]);

        $response = $this->get(route('security.enroll', ['type' => 'code']));

        $response->assertOk()->assertJsonPath('held', [['id' => $held, 'label' => null, 'removable' => false]]);
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
        $this->get(route('security.enroll', ['type' => 'code']));
        $this->travel(599)->seconds();
        expect(Keystone::guard()->slots()->get('code', Surface::ENROLLMENT->value))->not->toBeNull();

        $this->travel(1)->seconds();

        expect(Keystone::guard()->slots()->get('code', Surface::ENROLLMENT->value))->toBeNull();
        $this->assertAuthenticatedAs($account);
    });

    it('shows a new ceremony under the next grant, never the one the last grant opened', function (Closure $lose) {
        $this->freezeSecond();
        $this->signInAccount(new FormTypeSupport);
        $this->get(route('security.enroll', ['type' => 'code']));
        $first = $this->enrollmentCeremony('code');
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
        $this->get(route('security.enroll', ['type' => 'code']));
        $ceremony = $this->enrollmentCeremony('code');
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
        $this->get(route('security.enroll', ['type' => 'code']));
        $ceremony = $this->enrollmentCeremony('code');
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
        $this->get(route('security.enroll', ['type' => 'code']));
        $ceremony = $this->enrollmentCeremony('code');

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
        $this->get(route('security.enroll', ['type' => 'single']));
        $ceremony = $this->enrollmentCeremony('single');
        $sessionId = session()->getId();

        $response = $this->post(route('security.enroll.submit', ['type' => 'single']), ['secret' => $ceremony]);

        $response->assertRedirectToRoute('security');
        $left = DB::table('user_credentials')->where('user_id', $account->getKey())->where('type', 'single')->sole();
        expect($left->disabled_at)->toBeNull()
            ->and(DB::table('users')->where('id', $account->getKey())->value('credential_epoch'))->toEqual(1)
            ->and(session()->getId())->not->toBe($sessionId)
            ->and(SecurityEvent::query()->whereIn('type', ['credential.added', 'credential.removed', 'credential.replaced'])->pluck('type')->all())->toBe([SecurityEventType::CREDENTIAL_REPLACED]);
        $this->get(route('security'))->assertOk()
            ->assertJsonPath('sudoEndsAt', now()->addMinutes(15)->toIso8601String())
            ->assertJsonPath('status', __('keystone::messages.status.credential-replaced'));
        $this->assertDatabaseHas('user_credentials', ['user_id' => $account->getKey(), 'type' => 'form']);
        $this->assertAuthenticatedAs($account);
    });

    it('records credential.replaced in the settings flow, naming the new credential, and alerts the owner when a replacing type takes the place of a held one', function () {
        $this->app->make(CredentialTypes::class)->register(new FormType(name: 'single', surfaces: ['challenge', 'enrollment'], replacesExisting: true));
        $account = $this->signInAccount(new FormTypeSupport);
        DB::table('user_credentials')->insert(['user_id' => $account->getKey(), 'type' => 'single', 'secret' => Crypt::encryptString('held')]);
        $this->get(route('security.enroll', ['type' => 'single']));
        $ceremony = $this->enrollmentCeremony('single');
        Notification::fake();

        $this->post(route('security.enroll.submit', ['type' => 'single']), ['secret' => $ceremony]);

        $stored = DB::table('user_credentials')->where('user_id', $account->getKey())->where('type', 'single')->sole();
        $this->assertDatabaseHas('user_security_events', ['type' => 'credential.replaced', 'user_id' => $account->getKey(), 'flow' => 'settings', 'credential_type' => 'single', 'credential_id' => $stored->id]);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'credential.added']);
        Notification::assertSentOnDemandTimes(SecurityAlert::class, 1);
        Notification::assertSentOnDemand(SecurityAlert::class, fn (SecurityAlert $alert) => $alert->type === SecurityEventType::CREDENTIAL_REPLACED);
    });

    it('records credential.added for a replacing type\'s first credential', function () {
        $this->app->make(CredentialTypes::class)->register(new FormType(name: 'single', surfaces: ['challenge', 'enrollment'], replacesExisting: true));
        $account = $this->signInAccount(new FormTypeSupport);
        $this->get(route('security.enroll', ['type' => 'single']));

        $this->post(route('security.enroll.submit', ['type' => 'single']), ['secret' => $this->enrollmentCeremony('single')]);

        $this->assertDatabaseHas('user_security_events', ['type' => 'credential.added', 'user_id' => $account->getKey(), 'credential_type' => 'single']);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'credential.replaced']);
        $this->get(route('security'))->assertJsonPath('status', __('keystone::messages.status.enrolled'));
    });

    it('adds over a replacing type\'s disabled credential: deletes it, records credential.added and moves no epoch', function () {
        $this->app->make(CredentialTypes::class)->register(new FormType(name: 'single', surfaces: ['challenge', 'enrollment'], replacesExisting: true));
        $account = $this->signInAccount(new FormTypeSupport);
        $disabled = DB::table('user_credentials')->insertGetId(['user_id' => $account->getKey(), 'type' => 'single', 'secret' => Crypt::encryptString('disabled'), 'disabled_at' => now()]);
        $this->get(route('security.enroll', ['type' => 'single']));

        $this->post(route('security.enroll.submit', ['type' => 'single']), ['secret' => $this->enrollmentCeremony('single')]);

        $this->assertDatabaseMissing('user_credentials', ['id' => $disabled]);
        expect(DB::table('user_credentials')->where('user_id', $account->getKey())->where('type', 'single')->count())->toBe(1);
        $this->assertDatabaseHas('user_security_events', ['type' => 'credential.added', 'user_id' => $account->getKey(), 'credential_type' => 'single']);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'credential.replaced']);
        $this->assertDatabaseHas('users', ['id' => $account->getKey(), 'credential_epoch' => 0]);
        $this->get(route('security'))->assertJsonPath('status', __('keystone::messages.status.enrolled'));
    });

    it('leaves another account\'s credentials of a replacing type alone', function () {
        $this->app->make(CredentialTypes::class)->register(new FormType(name: 'single', surfaces: ['challenge', 'enrollment'], replacesExisting: true));
        $stranger = DB::table('user_credentials')->insertGetId(['user_id' => $this->createAccount('john@example.com')->getKey(), 'type' => 'single', 'secret' => Crypt::encryptString('held')]);
        $this->signInAccount(new FormTypeSupport);
        $this->get(route('security.enroll', ['type' => 'single']));

        $this->post(route('security.enroll.submit', ['type' => 'single']), ['secret' => $this->enrollmentCeremony('single')]);

        $this->assertDatabaseHas('user_credentials', ['id' => $stranger]);
    });

    it('adds beside a held credential with the same secret when the type doesn\'t replace', function () {
        $account = $this->signInAccount(new FormTypeSupport);
        $this->get(route('security.enroll', ['type' => 'code']));
        $ceremony = $this->enrollmentCeremony('code');
        DB::table('user_credentials')->insert(['user_id' => $account->getKey(), 'type' => 'code', 'secret' => Crypt::encryptString(FormType::hash($ceremony))]);

        $this->post(route('security.enroll.submit', ['type' => 'code']), ['secret' => $ceremony]);

        expect(DB::table('user_credentials')->where('user_id', $account->getKey())->where('type', 'code')->count())->toBe(2);
    });

    it('stores the label the type gives the credential and records it on credential.added', function () {
        $this->app->make(CredentialTypes::class)->register(new RogueType(fn () => Proof::enrolled(new EnrolledCredential(identifier: null, secret: 'rogue-secret', label: 'Phone')), surfaces: ['challenge', 'enrollment']));
        $account = $this->signInAccount(new FormTypeSupport);
        $this->get(route('security.enroll', ['type' => 'rogue']));

        $this->post(route('security.enroll.submit', ['type' => 'rogue']));

        $stored = DB::table('user_credentials')->where('user_id', $account->getKey())->where('type', 'rogue')->sole();
        expect(Crypt::decryptString($stored->secret))->toBe('rogue-secret')
            ->and($stored->label)->toBe('Phone');
        $this->assertDatabaseHas('user_security_events', ['type' => 'credential.added', 'credential_id' => $stored->id, 'credential_label' => 'Phone']);
    });

    it('keeps another type\'s running ceremony after an enrollment', function () {
        $this->app->make(CredentialTypes::class)->register(new FormType(name: 'other', surfaces: ['challenge', 'enrollment']));
        $this->signInAccount(new FormTypeSupport);
        $this->get(route('security.enroll', ['type' => 'other']));
        $other = $this->enrollmentCeremony('other');
        $this->get(route('security.enroll', ['type' => 'code']));

        $this->post(route('security.enroll.submit', ['type' => 'code']), ['secret' => $this->enrollmentCeremony('code')]);

        expect($this->enrollmentCeremony('other'))->toBe($other);
    });

    it('refuses a wrong answer, stores nothing and records the rejection in the settings flow', function () {
        $account = $this->signInAccount(new FormTypeSupport);
        $this->get(route('security.enroll', ['type' => 'code']));
        $ceremony = $this->enrollmentCeremony('code');

        $response = $this->post(route('security.enroll.submit', ['type' => 'code']), ['secret' => 'wrong '.$ceremony]);

        $response->assertRedirectToRoute('security.enroll', ['type' => 'code'])
            ->assertSessionHasErrors(['code' => __('keystone::messages.invalid_credential')]);
        $this->assertDatabaseMissing('user_credentials', ['type' => 'code']);
        $this->assertDatabaseHas('user_security_events', ['type' => 'proof.rejected', 'user_id' => $account->getKey(), 'flow' => 'settings', 'credential_type' => 'code', 'reason' => 'code.mismatch']);
    });

    it('shows the same ceremony again after a wrong answer, with nothing typed flashed back', function () {
        $this->signInAccount(new FormTypeSupport);
        $this->get(route('security.enroll', ['type' => 'code']));
        $ceremony = $this->enrollmentCeremony('code');
        $this->post(route('security.enroll.submit', ['type' => 'code']), ['secret' => 'wrong '.$ceremony]);

        $response = $this->get(route('security.enroll', ['type' => 'code']));

        expect($response->json('ceremony.code'))->toBe($ceremony)
            ->and(session()->getOldInput())->toBe([]);
    });

    it('waits out the timing floor on a wrong answer', function () {
        $this->signInAccount(new FormTypeSupport);
        $this->get(route('security.enroll', ['type' => 'code']));
        $ceremony = $this->enrollmentCeremony('code');

        $response = $this->assertWaitsOutTimingFloor(fn () => $this->post(route('security.enroll.submit', ['type' => 'code']), ['secret' => 'wrong '.$ceremony]));

        $response->assertRedirectToRoute('security.enroll', ['type' => 'code']);
    });

    it('counts wrong answers in the settings flow', function () {
        config(['keystone.rate_limits.failed_attempts_per_hour' => 1]);
        $account = $this->signInAccount(new FormTypeSupport);
        $this->get(route('security.enroll', ['type' => 'code']));
        $ceremony = $this->enrollmentCeremony('code');
        $this->post(route('security.enroll.submit', ['type' => 'code']), ['secret' => 'wrong '.$ceremony]);

        $response = $this->post(route('security.enroll.submit', ['type' => 'code']), ['secret' => $ceremony]);

        $response->assertTooManyRequests();
        $this->assertDatabaseMissing('user_credentials', ['type' => 'code']);
        $this->assertDatabaseHas('user_security_events', ['type' => 'limit.tripped', 'user_id' => $account->getKey(), 'flow' => 'settings', 'credential_type' => 'code']);
    });

    it('leaves sudo\'s count alone after wrong answers in the settings flow', function () {
        config(['keystone.rate_limits.failed_attempts_per_hour' => 1]);
        $this->signInAccount(new FormTypeSupport);
        $this->get(route('security.enroll', ['type' => 'code']));
        $this->post(route('security.enroll.submit', ['type' => 'code']), ['secret' => 'wrong '.$this->enrollmentCeremony('code')]);
        $this->delete(route('sudo.end'));
        $this->get(route('security.enroll', ['type' => 'code']));

        $response = $this->post(route('sudo.submit', ['type' => 'form']), (new FormTypeSupport)->validProof(Surface::SIGN_IN));

        $response->assertRedirect(route('security.enroll', ['type' => 'code'], absolute: false));
    });

    it('gives a right answer\'s attempt back', function () {
        config(['keystone.rate_limits.failed_attempts_per_hour' => 1]);
        $this->signInAccount(new FormTypeSupport);

        foreach (range(1, 3) as $ignored) {
            $this->get(route('security.enroll', ['type' => 'code']));

            $this->post(route('security.enroll.submit', ['type' => 'code']), ['secret' => $this->enrollmentCeremony('code')])->assertRedirectToRoute('security');
        }

        expect(DB::table('user_credentials')->where('type', 'code')->count())->toBe(3);
    });

    it('returns input the type\'s rules refuse to the step with its errors', function () {
        $this->signInAccount(new FormTypeSupport);
        $this->get(route('security.enroll', ['type' => 'code']));

        $response = $this->post(route('security.enroll.submit', ['type' => 'code']), []);

        $response->assertRedirectToRoute('security.enroll', ['type' => 'code'])->assertSessionHasErrors(['secret']);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'proof.rejected']);
    });

    it('takes no failed attempt for input the type\'s rules refuse', function () {
        config(['keystone.rate_limits.failed_attempts_per_hour' => 1]);
        $this->signInAccount(new FormTypeSupport);
        $this->get(route('security.enroll', ['type' => 'code']));
        $this->post(route('security.enroll.submit', ['type' => 'code']), []);

        $response = $this->post(route('security.enroll.submit', ['type' => 'code']), ['secret' => $this->enrollmentCeremony('code')]);

        $response->assertRedirectToRoute('security');
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
        $this->get(route('security.enroll', ['type' => 'code']));
        $ceremony = $this->enrollmentCeremony('code');
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
        $this->get(route('security.enroll', ['type' => 'rogue']));

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
        $this->get(route('security.enroll', ['type' => 'rogue']));

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
        $this->get(route('security.enroll', ['type' => 'rogue']));

        $this->post(route('security.enroll.submit', ['type' => 'rogue']));

        $this->assertDatabaseMissing('user_credentials', ['type' => 'rogue']);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'credential.added']);
        $this->assertDatabaseHas('user_security_events', ['type' => 'proof.rejected', 'user_id' => $account->getKey(), 'flow' => 'settings', 'reason' => 'keystone.barred']);
    });

    it('refuses a replacing answer as superseded when the credentials it was checked against changed meanwhile, writing nothing', function () {
        $this->freezeSecond();
        $account = $this->signInAccount(new FormTypeSupport);
        $held = DB::table('user_credentials')->insertGetId(['user_id' => $account->getKey(), 'type' => 'rogue', 'secret' => Crypt::encryptString('held')]);
        $this->app->make(CredentialTypes::class)->register(new RogueType(function () use ($account, $held) {
            DB::table('user_credentials')->where('id', $held)->delete();
            DB::table('user_credentials')->insert(['user_id' => $account->getKey(), 'type' => 'rogue', 'secret' => Crypt::encryptString('changed meanwhile')]);

            return Proof::enrolled(EnrolledCredential::replacing(identifier: null, secret: 'rogue-secret'));
        }, surfaces: ['challenge', 'enrollment']));
        $this->get(route('security.enroll', ['type' => 'rogue']));

        $response = $this->post(route('security.enroll.submit', ['type' => 'rogue']));

        $response->assertRedirectToRoute('security.enroll', ['type' => 'rogue'])->assertSessionHasErrors(['rogue' => __('keystone::messages.invalid_credential')]);
        $left = DB::table('user_credentials')->where('user_id', $account->getKey())->where('type', 'rogue')->sole();
        expect(Crypt::decryptString($left->secret))->toBe('changed meanwhile');
        $this->assertDatabaseHas('user_security_events', ['type' => 'proof.rejected', 'user_id' => $account->getKey(), 'flow' => 'settings', 'credential_type' => 'rogue', 'reason' => 'keystone.superseded']);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'credential.replaced']);
        $this->assertDatabaseHas('users', ['id' => $account->getKey(), 'credential_epoch' => 0]);
    });

    it('stores nothing and records nothing when the write fails', function () {
        Exceptions::fake();
        $this->signInAccount(new FormTypeSupport);
        $this->get(route('security.enroll', ['type' => 'code']));
        DB::connection()->beforeExecuting(function (string $query) {
            if (str_starts_with($query, 'insert') && str_contains($query, 'user_credentials')) {
                throw new RuntimeException('The write failed.');
            }
        });

        $response = $this->post(route('security.enroll.submit', ['type' => 'code']), ['secret' => $this->enrollmentCeremony('code')]);

        $response->assertServerError();
        $this->assertDatabaseMissing('user_credentials', ['type' => 'code']);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'credential.added']);
    });

    it('stores one credential when the answer is sent again after a failed write', function () {
        Exceptions::fake();
        $account = $this->signInAccount(new FormTypeSupport);
        $this->get(route('security.enroll', ['type' => 'code']));
        $ceremony = $this->enrollmentCeremony('code');
        $fails = true;
        DB::connection()->beforeExecuting(function (string $query) use (&$fails) {
            if ($fails && str_starts_with($query, 'insert') && str_contains($query, 'user_credentials')) {
                throw new RuntimeException('The write failed.');
            }
        });
        $this->post(route('security.enroll.submit', ['type' => 'code']), ['secret' => $ceremony])->assertServerError();
        $fails = false;

        $response = $this->post(route('security.enroll.submit', ['type' => 'code']), ['secret' => $ceremony]);

        $response->assertRedirectToRoute('security');
        expect(DB::table('user_credentials')->where('user_id', $account->getKey())->where('type', 'code')->count())->toBe(1);
    });
});

describe('the same answer sent twice at once', function () {
    it('stores a first credential of a replacing type once: one row, no epoch move, one event and one alert', function () {
        $this->app->make(CredentialTypes::class)->register(new FormType(name: 'single', surfaces: ['challenge', 'enrollment'], replacesExisting: true));
        $account = $this->signInAccount(new FormTypeSupport);
        $this->get(route('security.enroll', ['type' => 'single']));
        $ceremony = $this->enrollmentCeremony('single');
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

describe('the same replacing answer sent twice at once', function () {
    it('says the second one replaced the held credential too, storing one credential and recording one event', function () {
        $this->app->make(CredentialTypes::class)->register(new FormType(name: 'single', surfaces: ['challenge', 'enrollment'], replacesExisting: true));
        $account = $this->signInAccount(new FormTypeSupport);
        $held = DB::table('user_credentials')->insertGetId(['user_id' => $account->getKey(), 'type' => 'single', 'secret' => Crypt::encryptString('held')]);
        $this->get(route('security.enroll', ['type' => 'single']));
        $ceremony = $this->enrollmentCeremony('single');
        $armed = true;
        DB::listen(function (QueryExecuted $query) use (&$armed, $account, $held, $ceremony) {
            if (! $armed || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, '"type" = ?') || ! in_array('single', $query->bindings, true)) {
                return;
            }

            $armed = false;
            DB::table('user_credentials')->where('id', $held)->delete();
            DB::table('user_credentials')->insert(['user_id' => $account->getKey(), 'type' => 'single', 'secret' => Crypt::encryptString(FormType::hash($ceremony))]);
        });

        $response = $this->post(route('security.enroll.submit', ['type' => 'single']), ['secret' => $ceremony]);

        $response->assertRedirectToRoute('security');
        $this->get(route('security'))->assertJsonPath('status', __('keystone::messages.status.credential-replaced'));
        expect(DB::table('user_credentials')->where('user_id', $account->getKey())->where('type', 'single')->count())->toBe(1);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'credential.replaced']);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'proof.rejected']);
    });
});

describe('a replacement whose write fails', function () {
    it('keeps the credential the account holds, records nothing and moves no epoch', function () {
        Exceptions::fake();
        $this->app->make(CredentialTypes::class)->register(new FormType(name: 'single', surfaces: ['challenge', 'enrollment'], replacesExisting: true));
        $account = $this->signInAccount(new FormTypeSupport);
        $held = DB::table('user_credentials')->insertGetId(['user_id' => $account->getKey(), 'type' => 'single', 'secret' => Crypt::encryptString('held')]);
        $this->get(route('security.enroll', ['type' => 'single']));
        $ceremony = $this->enrollmentCeremony('single');
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
    it('closes the ceremony and sends the user to the security page', function () {
        $this->signInAccount(new FormTypeSupport);
        $this->get(route('security.enroll', ['type' => 'code']));

        $response = $this->delete(route('security.enroll.cancel', ['type' => 'code']));

        $response->assertRedirectToRoute('security');
        expect($this->enrollmentCeremony('code'))->toBeNull();
    });

    it('starts another ceremony on the next visit', function () {
        $this->signInAccount(new FormTypeSupport);
        $this->get(route('security.enroll', ['type' => 'code']));
        $first = $this->enrollmentCeremony('code');
        $this->delete(route('security.enroll.cancel', ['type' => 'code']));

        $this->get(route('security.enroll', ['type' => 'code']));

        expect($this->enrollmentCeremony('code'))->not->toBe($first);
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
