<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\CeremonySlots;
use ClaudioDekker\Keystone\Jobs\EndSessions;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Proof;
use ClaudioDekker\Keystone\Methods\StoredCredential;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Notifications\SecurityAlert;
use ClaudioDekker\Keystone\SecurityEvent;
use ClaudioDekker\Keystone\Tests\Fixtures\FormType;
use ClaudioDekker\Keystone\Tests\Fixtures\FormTypeSupport;
use ClaudioDekker\Keystone\Tests\Fixtures\RogueType;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

pest()->extend(AppTestCase::class);

beforeEach(function () {
    $this->withoutMandates();
    Route::middleware('web')->get('whoami', fn () => (string) (auth()->id() ?? 'guest'));
    Route::middleware(['web', 'auth'])->post('end-my-sessions', fn () => EndSessions::dispatchSync(auth()->user()));
    Route::middleware(['web', 'sudo'])->match(['get', 'post'], 'probe', fn () => 'the gated page');
});

/**
 * Sign the account in, end its sudo and let the gate refuse it, so the session holds a sudo-in-progress owing its first step.
 */
function demandSudo(AppTestCase $test, string $intended = 'probe'): void
{
    $test->delete(route('sudo.end'));
    $test->get($intended);
}

/**
 * Make the test client act as the browser holding the session, which the store saved under its id after that browser's last request.
 */
function asBrowser(string $sessionId): void
{
    session()->setId($sessionId);
    session()->flush();
}

/**
 * Make the test client act as a fresh browser, returning the session id to come back to.
 */
function otherBrowser(): string
{
    $current = session()->getId();

    asBrowser(Str::random(40));

    return $current;
}

describe('the sudo page', function () {
    it('offers the sign-in types the account holds and no others', function () {
        $this->app->make(CredentialTypes::class)->register(new FormType(name: 'unheld', surfaces: ['sign-in']));
        $this->signInAccount(new FormTypeSupport);
        demandSudo($this);

        $response = $this->get(route('sudo'));

        $response->assertOk()->assertExactJson([
            'types' => [['type' => 'form', 'shape' => 'form']],
            'preselect' => 'form',
            'surface' => 'sign-in',
        ]);
    });

    it('offers the account\'s challenge types and recovery codes once the first factor passed, without that factor\'s type', function () {
        $this->app->make(CredentialTypes::class)->register(new FormType(name: 'both', surfaces: ['sign-in', 'challenge']));
        $this->app->instance('keystone.test-support.both', new FormTypeSupport('both'));
        $account = $this->createChallengedAccount(new FormTypeSupport('code'));
        $this->arrangeCredential($account, new FormTypeSupport('both'), Surface::CHALLENGE);
        $this->arrangeRecoveryCodes($account);
        $this->passFirstFactor();
        $this->post(route('login.challenge.submit', ['type' => 'code']), (new FormTypeSupport('code'))->validProof(Surface::CHALLENGE));
        demandSudo($this);
        $this->post(route('sudo.submit', ['type' => 'both']), (new FormTypeSupport('both'))->validProof(Surface::SIGN_IN))->assertRedirectToRoute('sudo');

        $response = $this->get(route('sudo'));

        $response->assertOk()->assertExactJson([
            'types' => [['type' => 'code', 'shape' => 'form'], ['type' => 'recovery-code', 'shape' => 'form']],
            'preselect' => 'code',
            'surface' => 'challenge',
        ]);
    });

    it('shows an empty offer for an account that holds nothing it can answer with', function () {
        $account = $this->signInAccount(new FormTypeSupport);
        demandSudo($this);
        DB::table('user_credentials')->where('user_id', $account->getKey())->update(['disabled_at' => now()]);

        $response = $this->get(route('sudo'));

        $response->assertOk()->assertExactJson(['types' => [], 'preselect' => null, 'surface' => 'sign-in']);
    });

    it('sends the user on without sudo when the second factor they owed went away', function (int $codes) {
        $account = $this->createChallengedAccount(new FormTypeSupport('code'));
        $this->arrangeRecoveryCodes($account, count: $codes);
        $this->passFirstFactor();
        $this->post(route('login.challenge.submit', ['type' => 'code']), (new FormTypeSupport('code'))->validProof(Surface::CHALLENGE));
        demandSudo($this);
        $this->post(route('sudo.submit', ['type' => 'form']), (new FormTypeSupport)->validProof(Surface::SIGN_IN))->assertRedirectToRoute('sudo');
        DB::table('user_credentials')->where('user_id', $account->getKey())->where('type', 'code')->delete();

        $response = $this->get(route('sudo'));

        $response->assertRedirect('/probe');
        $this->get('probe')->assertRedirectToRoute('sudo');
        $this->get(route('sudo'))->assertJsonPath('surface', 'sign-in');
        $this->assertDatabaseCount('user_recovery_codes', $codes);
    })->with(['holding no recovery codes' => 0, 'holding recovery codes a sign-in would not ask for' => 8]);

    it('sends a session nothing was demanded of away', function (Closure $arrange) {
        $arrange($this);

        $response = $this->get(route('sudo'));

        $response->assertRedirect('/');
    })->with([
        'with a live grant' => [fn (AppTestCase $test) => $test->signInAccount(new FormTypeSupport)],
        'with no grant and no refusal' => [function (AppTestCase $test) {
            $test->signInAccount(new FormTypeSupport);
            $test->delete(route('sudo.end'));
        }],
    ]);

    it('sends a guest to sign-in', function () {
        $response = $this->get(route('sudo'));

        $response->assertRedirectToRoute('login');
    });

    it('stops offering fifteen minutes after the gate first refused, however often the gate refused since', function () {
        $this->freezeSecond();
        $this->signInAccount(new FormTypeSupport);
        demandSudo($this);
        $this->travel(899)->seconds();
        $this->get('probe')->assertRedirectToRoute('sudo');
        $this->get(route('sudo'))->assertOk();
        $this->travel(1)->seconds();

        $response = $this->get(route('sudo'));

        $response->assertRedirect('/');
    });

    it('shows inside the timing floor', function () {
        $this->signInAccount(new FormTypeSupport);
        demandSudo($this);

        $this->assertWaitsOutTimingFloor(fn () => $this->get(route('sudo')))->assertOk();
    });

    it('limits requests to the page', function () {
        $this->signInAccount(new FormTypeSupport);
        demandSudo($this);

        foreach (range(1, config()->integer('keystone.rate_limits.requests_per_minute.view')) as $ignored) {
            $this->get(route('sudo'))->assertOk();
        }

        $this->get(route('sudo'))->assertTooManyRequests();
    });
});

describe('the replay', function () {
    it('grants sudo for the first factor alone when the account holds no second factor', function () {
        $account = $this->signInAccount(new FormTypeSupport);
        demandSudo($this);

        $response = $this->post(route('sudo.submit', ['type' => 'form']), (new FormTypeSupport)->validProof(Surface::SIGN_IN));

        $response->assertRedirect('/probe');
        $this->assertAuthenticatedAs($account);
        $this->get('probe')->assertOk();
    });

    it('never grants an account holding a second factor for its first factor alone', function () {
        $this->createChallengedAccount(new FormTypeSupport('code'));
        $this->passFirstFactor();
        $this->post(route('login.challenge.submit', ['type' => 'code']), (new FormTypeSupport('code'))->validProof(Surface::CHALLENGE));
        demandSudo($this);

        $response = $this->post(route('sudo.submit', ['type' => 'form']), (new FormTypeSupport)->validProof(Surface::SIGN_IN));

        $response->assertRedirectToRoute('sudo');
        $this->get('probe')->assertRedirectToRoute('sudo');
        expect(SecurityEvent::query()->where('type', 'sudo.granted')->count())->toBe(1);
    });

    it('grants once the challenge a sign-in would demand is answered', function () {
        $this->createChallengedAccount(new FormTypeSupport('code'));
        $this->passFirstFactor();
        $this->post(route('login.challenge.submit', ['type' => 'code']), (new FormTypeSupport('code'))->validProof(Surface::CHALLENGE));
        demandSudo($this);
        $this->post(route('sudo.submit', ['type' => 'form']), (new FormTypeSupport)->validProof(Surface::SIGN_IN));

        $response = $this->post(route('sudo.submit', ['type' => 'code']), (new FormTypeSupport('code'))->validProof(Surface::CHALLENGE));

        $response->assertRedirect('/probe');
        $this->get('probe')->assertOk();
    });

    it('grants without a challenge for a type that proves two factors on its own', function () {
        $this->app->make(CredentialTypes::class)->register(new FormType(name: 'passkey', surfaces: ['sign-in', 'challenge'], multipleFactors: true));
        $this->app->instance('keystone.test-support.passkey', new FormTypeSupport('passkey'));
        $account = $this->createChallengedAccount(new FormTypeSupport('code'));
        $this->arrangeCredential($account, new FormTypeSupport('passkey'), Surface::SIGN_IN);
        $this->passFirstFactor();
        $this->post(route('login.challenge.submit', ['type' => 'code']), (new FormTypeSupport('code'))->validProof(Surface::CHALLENGE));
        demandSudo($this);

        $response = $this->post(route('sudo.submit', ['type' => 'passkey']), (new FormTypeSupport('passkey'))->validProof(Surface::SIGN_IN));

        $response->assertRedirect('/probe');
        $this->get('probe')->assertOk();
    });

    it('sends the user back to the page a refused GET asked for', function () {
        $this->signInAccount(new FormTypeSupport);
        demandSudo($this, 'probe?tab=keys');

        $response = $this->post(route('sudo.submit', ['type' => 'form']), (new FormTypeSupport)->validProof(Surface::SIGN_IN));

        $response->assertRedirect('/probe?tab=keys');
    });

    it('sends the user back to the page a refused mutation came from, and never replays the mutation', function (Closure $refused, string $intended) {
        $this->signInAccount(new FormTypeSupport);
        $this->delete(route('sudo.end'));
        $refused($this);

        $response = $this->post(route('sudo.submit', ['type' => 'form']), (new FormTypeSupport)->validProof(Surface::SIGN_IN));

        $response->assertRedirect($intended);
    })->with([
        'a POST' => [fn (AppTestCase $test) => $test->from('/settings/security')->post('probe'), '/settings/security'],
        'a JSON GET' => [fn (AppTestCase $test) => $test->from('/settings/security')->getJson('probe'), '/settings/security'],
        'a cross-origin referrer falls back to the root' => [fn (AppTestCase $test) => $test->from('https://evil.example/settings')->post('probe'), '/'],
    ]);

    it('sends the user back to the page a refused GET asked for on a host that differs from app.url', function () {
        config(['app.url' => 'https://app.example']);
        $this->signInAccount(new FormTypeSupport);
        $this->delete(route('sudo.end'));
        $this->get('http://alias.example/probe?tab=keys')->assertRedirectToRoute('sudo');

        $response = $this->post(route('sudo.submit', ['type' => 'form']), (new FormTypeSupport)->validProof(Surface::SIGN_IN));

        $response->assertRedirect('/probe?tab=keys');
    });

    it('takes the page of the latest refused GET while keeping the time the sudo-in-progress started', function () {
        $this->freezeSecond();
        $this->signInAccount(new FormTypeSupport);
        demandSudo($this, 'probe?first=1');
        $this->travel(899)->seconds();
        $this->get('probe?second=1')->assertRedirectToRoute('sudo');

        $this->post(route('sudo.submit', ['type' => 'form']), (new FormTypeSupport)->validProof(Surface::SIGN_IN))->assertRedirect('/probe?second=1');

        demandSudo($this, 'probe?third=1');
        $this->travel(900)->seconds();
        $this->get('probe?fourth=1')->assertRedirectToRoute('sudo');
        $this->travel(899)->seconds();

        $this->post(route('sudo.submit', ['type' => 'form']), (new FormTypeSupport)->validProof(Surface::SIGN_IN))->assertRedirect('/probe?fourth=1');
    });

    it('keeps the page a refused GET asked for when a mutation or a JSON request is refused meanwhile', function (Closure $refused) {
        $this->signInAccount(new FormTypeSupport);
        demandSudo($this, 'probe?tab=keys');
        $refused($this);

        $response = $this->post(route('sudo.submit', ['type' => 'form']), (new FormTypeSupport)->validProof(Surface::SIGN_IN));

        $response->assertRedirect('/probe?tab=keys');
    })->with([
        'a POST from the sudo page' => [fn (AppTestCase $test) => $test->from(route('sudo'))->post('probe')],
        'a JSON GET from the sudo page' => [fn (AppTestCase $test) => $test->from(route('sudo'))->getJson('probe')],
    ]);

    it('keeps the first factor that passed when the gate refuses again', function () {
        $this->createChallengedAccount(new FormTypeSupport('code'));
        $this->passFirstFactor();
        $this->post(route('login.challenge.submit', ['type' => 'code']), (new FormTypeSupport('code'))->validProof(Surface::CHALLENGE));
        demandSudo($this);
        $this->post(route('sudo.submit', ['type' => 'form']), (new FormTypeSupport)->validProof(Surface::SIGN_IN));

        $this->get('probe?again=1')->assertRedirectToRoute('sudo');

        $this->get(route('sudo'))->assertJsonPath('surface', 'challenge');
        $this->post(route('sudo.submit', ['type' => 'code']), (new FormTypeSupport('code'))->validProof(Surface::CHALLENGE))->assertRedirect('/probe?again=1');
    });

    it('rotates the session, binds the grant to the subnet it was earned from and clears the sudo-in-progress', function () {
        $this->createFirstFactorAccount();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])->submitSignIn(new FormTypeSupport, 'jane@example.com', (new FormTypeSupport)->validProof(Surface::SIGN_IN));
        demandSudo($this);
        $sessionId = session()->getId();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->post(route('sudo.submit', ['type' => 'form']), (new FormTypeSupport)->validProof(Surface::SIGN_IN))->assertRedirect('/probe');

        expect(session()->getId())->not->toBe($sessionId);
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.200'])->get('probe')->assertOk();
        $this->get(route('sudo'))->assertRedirect('/');
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])->get('probe')->assertRedirectToRoute('sudo');
    });

    it('records the grant with the credential that earned it and stamps that credential\'s last use', function () {
        $this->freezeSecond();
        $account = $this->signInAccount(new FormTypeSupport);
        $credentialId = DB::table('user_credentials')->where('user_id', $account->getKey())->value('id');
        demandSudo($this);
        $this->travel(5)->minutes();

        $this->post(route('sudo.submit', ['type' => 'form']), (new FormTypeSupport)->validProof(Surface::SIGN_IN));

        $granted = SecurityEvent::query()->where('type', 'sudo.granted')->where('flow', 'sudo')->sole();
        expect($granted->user_id)->toEqual($account->getKey())
            ->and($granted->credential_type)->toBe('form')
            ->and($granted->credential_id)->toBe($credentialId)
            ->and($granted->reason)->toBeNull()
            ->and($granted->known_device)->toBeNull()
            ->and(DB::table('user_credentials')->where('id', $credentialId)->value('last_used_at'))->toBe(now()->toDateTimeString());
    });

    it('verifies nothing for a session nothing was demanded of', function (Closure $arrange) {
        $rogue = new RogueType(fn () => throw new LogicException('verify must not run'), surfaces: ['sign-in']);
        $account = $arrange($this);
        $this->app->make(CredentialTypes::class)->register($rogue);
        DB::table('user_credentials')->insert(['user_id' => $account->getKey(), 'type' => 'rogue']);

        $response = $this->post(route('sudo.submit', ['type' => 'rogue']), ['secret' => 'anything']);

        $response->assertRedirect('/');
        expect($rogue->calls)->toBe([]);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'sudo.failed']);
    })->with([
        'with a live grant' => [fn (AppTestCase $test) => $test->signInAccount(new FormTypeSupport)],
        'with no grant and no refusal' => [function (AppTestCase $test) {
            $account = $test->signInAccount(new FormTypeSupport);
            $test->delete(route('sudo.end'));

            return $account;
        }],
    ]);

    it('verifies nothing and takes no failed attempt for a type the step doesn\'t offer', function (Closure $arrange, string $type, Closure $prove) {
        $this->freezeSecond();
        config(['keystone.rate_limits.failed_attempts_per_hour' => 1]);
        $rogue = new RogueType(fn () => throw new LogicException('verify must not run'), surfaces: ['sign-in', 'challenge']);
        $input = $arrange->call($this, $this);
        $this->app->make(CredentialTypes::class)->register($rogue);

        $response = $this->post(route('sudo.submit', ['type' => $type]), $input);

        $response->assertRedirectToRoute('sudo')->assertSessionHasErrors([$type => __('keystone::messages.invalid_credential')]);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'sudo.failed']);
        $this->get('probe')->assertRedirectToRoute('sudo');
        $prove->call($this, $this, $rogue);
    })->with([
        'a challenge type at the first step' => [
            function (AppTestCase $test) {
                $test->createChallengedAccount(new FormTypeSupport('code'));
                $test->passFirstFactor();
                $test->post(route('login.challenge.submit', ['type' => 'code']), (new FormTypeSupport('code'))->validProof(Surface::CHALLENGE));
                demandSudo($test);

                return (new FormTypeSupport('code'))->validProof(Surface::CHALLENGE);
            },
            'code',
            function (AppTestCase $test) {
                $test->post(route('sudo.submit', ['type' => 'form']), (new FormTypeSupport)->validProof(Surface::SIGN_IN))->assertRedirectToRoute('sudo');
                $test->travel(7)->seconds();
                $test->post(route('sudo.submit', ['type' => 'code']), (new FormTypeSupport('code'))->rejectedProof(Surface::CHALLENGE))->assertSessionHasErrors('code');
                $test->travel(7)->seconds();
                $test->post(route('sudo.submit', ['type' => 'code']), (new FormTypeSupport('code'))->rejectedProof(Surface::CHALLENGE))->assertTooManyRequests();
            },
        ],
        'the first factor\'s type at the challenge' => [
            function (AppTestCase $test) {
                $test->createChallengedAccount(new FormTypeSupport('code'));
                $test->passFirstFactor();
                $test->post(route('login.challenge.submit', ['type' => 'code']), (new FormTypeSupport('code'))->validProof(Surface::CHALLENGE));
                demandSudo($test);
                $test->post(route('sudo.submit', ['type' => 'form']), (new FormTypeSupport)->validProof(Surface::SIGN_IN));

                return (new FormTypeSupport)->validProof(Surface::SIGN_IN);
            },
            'form',
            function (AppTestCase $test) {
                demandSudo($test);
                $test->travel(7)->seconds();
                $test->post(route('sudo.submit', ['type' => 'form']), (new FormTypeSupport)->rejectedProof(Surface::SIGN_IN))->assertSessionHasErrors('form');
                $test->travel(7)->seconds();
                $test->post(route('sudo.submit', ['type' => 'form']), (new FormTypeSupport)->rejectedProof(Surface::SIGN_IN))->assertTooManyRequests();
            },
        ],
        'a type the account doesn\'t hold' => [
            function (AppTestCase $test) {
                $test->signInAccount(new FormTypeSupport);
                demandSudo($test);

                return ['secret' => 'anything'];
            },
            'rogue',
            fn (AppTestCase $test, RogueType $rogue) => expect($rogue->calls)->toBe([]),
        ],
        'no such type' => [
            function (AppTestCase $test) {
                $test->signInAccount(new FormTypeSupport);
                demandSudo($test);

                return ['secret' => 'anything'];
            },
            'no-such-type',
            fn (AppTestCase $test) => $test->assertDatabaseMissing('user_security_events', ['type' => 'limit.tripped']),
        ],
        'recovery codes at the first step' => [
            function (AppTestCase $test) {
                $account = $test->createChallengedAccount(new FormTypeSupport('code'));
                $codes = $test->arrangeRecoveryCodes($account);
                $test->passFirstFactor();
                $test->post(route('login.challenge.submit', ['type' => 'code']), (new FormTypeSupport('code'))->validProof(Surface::CHALLENGE));
                demandSudo($test);

                return ['code' => $codes[0]];
            },
            'recovery-code',
            function (AppTestCase $test) {
                $test->assertDatabaseCount('user_recovery_codes', 8);
                $test->assertDatabaseMissing('user_security_events', ['type' => 'recovery_code.used']);
            },
        ],
    ]);

    it('refuses invalid input without verifying, counting or recording anything', function () {
        $this->freezeSecond();
        config(['keystone.rate_limits.failed_attempts_per_hour' => 1]);
        $this->signInAccount(new FormTypeSupport);
        demandSudo($this);

        $response = $this->post(route('sudo.submit', ['type' => 'form']), []);

        $response->assertRedirectToRoute('sudo')->assertSessionHasErrors('secret');
        $this->assertDatabaseMissing('user_security_events', ['type' => 'sudo.failed']);
        $this->travel(7)->seconds();
        $this->post(route('sudo.submit', ['type' => 'form']), (new FormTypeSupport)->rejectedProof(Surface::SIGN_IN))->assertSessionHasErrors('form');
        $this->travel(7)->seconds();
        $this->post(route('sudo.submit', ['type' => 'form']), (new FormTypeSupport)->rejectedProof(Surface::SIGN_IN))->assertTooManyRequests();
    });

    it('verifies nothing once the sudo-in-progress has expired', function () {
        $this->freezeSecond();
        $rogue = new RogueType(fn () => throw new LogicException('verify must not run'), surfaces: ['sign-in']);
        $account = $this->signInAccount(new FormTypeSupport);
        $this->app->make(CredentialTypes::class)->register($rogue);
        DB::table('user_credentials')->insert(['user_id' => $account->getKey(), 'type' => 'rogue']);
        demandSudo($this);
        $this->travel(900)->seconds();

        $response = $this->post(route('sudo.submit', ['type' => 'rogue']), ['secret' => 'anything']);

        $response->assertRedirect('/');
        expect($rogue->calls)->toBe([]);
        $this->get('probe')->assertRedirectToRoute('sudo');
    });

    it('refuses a wrong answer with the flattened message, records sudo.failed and alerts the owner', function () {
        $account = $this->signInAccount(new FormTypeSupport);
        demandSudo($this);
        Notification::fake();

        $response = $this->post(route('sudo.submit', ['type' => 'form']), (new FormTypeSupport)->rejectedProof(Surface::SIGN_IN));

        $response->assertRedirectToRoute('sudo')->assertSessionHasErrors(['form' => __('keystone::messages.invalid_credential')]);
        $this->get('probe')->assertRedirectToRoute('sudo');
        $failed = SecurityEvent::query()->where('type', 'sudo.failed')->sole();
        expect($failed->user_id)->toEqual($account->getKey())
            ->and($failed->flow)->toBe('sudo')
            ->and($failed->credential_type)->toBe('form')
            ->and($failed->reason)->toBe('form.mismatch')
            ->and($failed->known_device)->toBeNull();
        $this->assertDatabaseMissing('user_security_events', ['type' => 'proof.rejected']);
        Notification::assertSentOnDemand(SecurityAlert::class, fn (SecurityAlert $alert, array $channels, object $notifiable) => $alert->type->value === 'sudo.failed' && $notifiable->routes['mail'] === 'jane@example.com');
    });

    it('alerts about a wrong answer from a known device too', function () {
        $this->createFirstFactorAccount();
        $device = $this->deviceCookieOf($this->submitSignIn(new FormTypeSupport, 'jane@example.com', (new FormTypeSupport)->validProof(Surface::SIGN_IN)));
        $this->fromDevice($device);
        demandSudo($this);
        Notification::fake();

        $this->post(route('sudo.submit', ['type' => 'form']), (new FormTypeSupport)->rejectedProof(Surface::SIGN_IN));

        Notification::assertSentOnDemand(SecurityAlert::class, fn (SecurityAlert $alert) => $alert->type->value === 'sudo.failed');
    });

    it('waits out the timing floor on a wrong answer, and returns early once granted', function () {
        $this->signInAccount(new FormTypeSupport);
        demandSudo($this);

        $this->assertWaitsOutTimingFloor(fn () => $this->post(route('sudo.submit', ['type' => 'form']), (new FormTypeSupport)->rejectedProof(Surface::SIGN_IN)));

        Sleep::fake();

        $this->post(route('sudo.submit', ['type' => 'form']), (new FormTypeSupport)->validProof(Surface::SIGN_IN))->assertRedirect('/probe');

        Sleep::assertNeverSlept();
    });

    it('refuses with a 429 once the sudo flow\'s failed attempts are spent', function () {
        $this->freezeSecond();
        $this->signInAccount(new FormTypeSupport);
        demandSudo($this);

        foreach (range(1, 20) as $ignored) {
            $this->travel(7)->seconds();
            $this->post(route('sudo.submit', ['type' => 'form']), (new FormTypeSupport)->rejectedProof(Surface::SIGN_IN))->assertSessionHasErrors('form');
        }

        $this->post(route('sudo.submit', ['type' => 'form']), (new FormTypeSupport)->validProof(Surface::SIGN_IN))->assertTooManyRequests();
        $this->get('probe')->assertRedirectToRoute('sudo');
    });

    it('counts wrong answers in the sudo flow apart from sign-in\'s', function () {
        $this->freezeSecond();
        $this->signInAccount(new FormTypeSupport);
        demandSudo($this);

        foreach (range(1, 20) as $ignored) {
            $this->travel(7)->seconds();
            $this->post(route('sudo.submit', ['type' => 'form']), (new FormTypeSupport)->rejectedProof(Surface::SIGN_IN));
        }

        otherBrowser();

        $this->submitSignIn(new FormTypeSupport, 'jane@example.com', (new FormTypeSupport)->rejectedProof(Surface::SIGN_IN))->assertSessionHasErrors('identifier');
    });

    it('counts wrong TOTP codes on the count the challenge shares', function () {
        $this->freezeSecond();
        $this->app->make(CredentialTypes::class)->register(new FormType(name: 'otp', surfaces: ['challenge'], sharesFailedAttempts: true));
        $this->createChallengedAccount(new FormTypeSupport('otp'));
        $this->passFirstFactor();
        $this->post(route('login.challenge.submit', ['type' => 'otp']), (new FormTypeSupport('otp'))->validProof(Surface::CHALLENGE));
        demandSudo($this);
        $this->travel(1)->minute();
        $sudoSession = otherBrowser();
        $this->passFirstFactor();

        foreach (range(1, 19) as $ignored) {
            $this->travel(7)->seconds();
            $this->post(route('login.challenge.submit', ['type' => 'otp']), (new FormTypeSupport('otp'))->rejectedProof(Surface::CHALLENGE))->assertSessionHasErrors('otp');
        }

        asBrowser($sudoSession);
        $this->post(route('sudo.submit', ['type' => 'form']), (new FormTypeSupport)->validProof(Surface::SIGN_IN))->assertRedirectToRoute('sudo');
        $this->post(route('sudo.submit', ['type' => 'otp']), (new FormTypeSupport('otp'))->rejectedProof(Surface::CHALLENGE))->assertSessionHasErrors('otp');

        $this->post(route('sudo.submit', ['type' => 'otp']), (new FormTypeSupport('otp'))->validProof(Surface::CHALLENGE))->assertTooManyRequests();
        $this->get('probe')->assertRedirectToRoute('sudo');
    });

    it('accepts an answer once failures at the challenge spent the count of a type that shares none', function () {
        $this->freezeSecond();
        $this->createChallengedAccount(new FormTypeSupport('code'));
        $this->passFirstFactor();
        $this->post(route('login.challenge.submit', ['type' => 'code']), (new FormTypeSupport('code'))->validProof(Surface::CHALLENGE));
        demandSudo($this);
        $this->travel(1)->minute();
        $sudoSession = otherBrowser();
        $this->passFirstFactor();

        foreach (range(1, 20) as $ignored) {
            $this->travel(7)->seconds();
            $this->post(route('login.challenge.submit', ['type' => 'code']), (new FormTypeSupport('code'))->rejectedProof(Surface::CHALLENGE))->assertSessionHasErrors('code');
        }

        $this->post(route('login.challenge.submit', ['type' => 'code']), (new FormTypeSupport('code'))->validProof(Surface::CHALLENGE))->assertTooManyRequests();
        $this->travel(1)->minute();
        asBrowser($sudoSession);
        $this->post(route('sudo.submit', ['type' => 'form']), (new FormTypeSupport)->validProof(Surface::SIGN_IN))->assertRedirectToRoute('sudo');

        $response = $this->post(route('sudo.submit', ['type' => 'code']), (new FormTypeSupport('code'))->validProof(Surface::CHALLENGE));

        $response->assertRedirect('/probe');
        $this->get('probe')->assertOk();
    });

    it('leaves the counts of every other flow untouched when it grants', function () {
        $this->freezeSecond();
        $this->createChallengedAccount(new FormTypeSupport('code'));
        $this->passFirstFactor();
        $this->post(route('login.challenge.submit', ['type' => 'code']), (new FormTypeSupport('code'))->validProof(Surface::CHALLENGE));
        demandSudo($this);
        $this->travel(1)->minute();
        $sudoSession = otherBrowser();

        foreach (range(1, 19) as $ignored) {
            $this->travel(7)->seconds();
            $this->submitSignIn(new FormTypeSupport, 'jane@example.com', (new FormTypeSupport)->rejectedProof(Surface::SIGN_IN));
        }

        $this->travel(1)->minute();
        $signInSession = otherBrowser();
        $this->passFirstFactor();

        foreach (range(1, 19) as $ignored) {
            $this->travel(7)->seconds();
            $this->post(route('login.challenge.submit', ['type' => 'code']), (new FormTypeSupport('code'))->rejectedProof(Surface::CHALLENGE));
        }

        $challengeSession = session()->getId();
        asBrowser($sudoSession);
        $this->post(route('sudo.submit', ['type' => 'form']), (new FormTypeSupport)->validProof(Surface::SIGN_IN))->assertRedirectToRoute('sudo');
        $this->post(route('sudo.submit', ['type' => 'code']), (new FormTypeSupport('code'))->validProof(Surface::CHALLENGE))->assertRedirect('/probe');

        asBrowser($signInSession);
        $this->submitSignIn(new FormTypeSupport, 'jane@example.com', (new FormTypeSupport)->rejectedProof(Surface::SIGN_IN))->assertSessionHasErrors('identifier');
        $this->submitSignIn(new FormTypeSupport, 'jane@example.com', (new FormTypeSupport)->rejectedProof(Surface::SIGN_IN))->assertTooManyRequests();
        asBrowser($challengeSession);
        $this->post(route('login.challenge.submit', ['type' => 'code']), (new FormTypeSupport('code'))->rejectedProof(Surface::CHALLENGE))->assertSessionHasErrors('code');
        $this->post(route('login.challenge.submit', ['type' => 'code']), (new FormTypeSupport('code'))->rejectedProof(Surface::CHALLENGE))->assertTooManyRequests();
    });

    it('grants for a recovery code, spends it and records its use in the sudo flow', function () {
        $account = $this->createChallengedAccount(new FormTypeSupport('code'));
        $codes = $this->arrangeRecoveryCodes($account);
        $this->passFirstFactor();
        $this->post(route('login.challenge.submit', ['type' => 'code']), (new FormTypeSupport('code'))->validProof(Surface::CHALLENGE));
        demandSudo($this);
        $this->post(route('sudo.submit', ['type' => 'form']), (new FormTypeSupport)->validProof(Surface::SIGN_IN));

        $response = $this->post(route('sudo.submit', ['type' => 'recovery-code']), ['code' => $codes[0]]);

        $response->assertRedirect('/probe');
        $this->get('probe')->assertOk();
        $this->assertDatabaseCount('user_recovery_codes', 7);
        $this->assertDatabaseHas('user_security_events', ['type' => 'recovery_code.used', 'flow' => 'sudo', 'user_id' => $account->getKey()]);
        $granted = SecurityEvent::query()->where('type', 'sudo.granted')->where('flow', 'sudo')->sole();
        expect($granted->credential_type)->toBe('recovery-code')
            ->and($granted->credential_id)->toBeNull();
    });

    it('keeps the last recovery code', function (bool $mandated) {
        config(['keystone.require_recovery_codes' => $mandated]);
        $account = $this->createChallengedAccount(new FormTypeSupport('code'));
        $codes = $this->arrangeRecoveryCodes($account, count: 1);
        $this->passFirstFactor();
        $this->post(route('login.challenge.submit', ['type' => 'code']), (new FormTypeSupport('code'))->validProof(Surface::CHALLENGE));
        demandSudo($this);
        $this->post(route('sudo.submit', ['type' => 'form']), (new FormTypeSupport)->validProof(Surface::SIGN_IN));

        $response = $this->post(route('sudo.submit', ['type' => 'recovery-code']), ['code' => $codes[0]]);

        $response->assertRedirectToRoute('sudo')->assertSessionHasErrors(['recovery-code' => __('keystone::messages.last_recovery_code')]);
        $this->get('probe')->assertRedirectToRoute('sudo');
        $this->assertDatabaseCount('user_recovery_codes', 1);
        $this->assertDatabaseHas('user_security_events', ['type' => 'sudo.failed', 'reason' => 'keystone.last_recovery_code']);
    })->with(['recovery codes mandated' => true, 'recovery codes not mandated' => false]);

    it('refuses a wrong recovery code', function () {
        $account = $this->createChallengedAccount(new FormTypeSupport('code'));
        $this->arrangeRecoveryCodes($account);
        $this->passFirstFactor();
        $this->post(route('login.challenge.submit', ['type' => 'code']), (new FormTypeSupport('code'))->validProof(Surface::CHALLENGE));
        demandSudo($this);
        $this->post(route('sudo.submit', ['type' => 'form']), (new FormTypeSupport)->validProof(Surface::SIGN_IN));

        $response = $this->post(route('sudo.submit', ['type' => 'recovery-code']), ['code' => 'AAAAA-BBBBB-CCCCC-DDDDD-EEEEE-F']);

        $response->assertRedirectToRoute('sudo')->assertSessionHasErrors(['recovery-code' => __('keystone::messages.invalid_credential')]);
        $this->get('probe')->assertRedirectToRoute('sudo');
        $this->assertDatabaseCount('user_recovery_codes', 8);
        $this->assertDatabaseHas('user_security_events', ['type' => 'sudo.failed', 'reason' => 'recovery-code.mismatch']);
    });

    it('stores the secret an advanced proof moves its credential on to', function () {
        $account = $this->signInAccount(new FormTypeSupport);
        $id = DB::table('user_credentials')->insertGetId(['user_id' => $account->getKey(), 'type' => 'rogue', 'secret' => Crypt::encryptString('step-1')]);
        $this->app->make(CredentialTypes::class)->register(new RogueType(fn () => Proof::advanced(new StoredCredential($id, identifier: null, secret: 'step-1', label: null), 'step-2'), surfaces: ['sign-in']));
        demandSudo($this);

        $this->post(route('sudo.submit', ['type' => 'rogue']))->assertRedirect('/probe');

        expect(Crypt::decryptString(DB::table('user_credentials')->where('id', $id)->value('secret')))->toBe('step-2');
        $this->get('probe')->assertOk();
    });

    it('refuses an advanced proof once another proof moved its credential on first, and grants nothing', function () {
        $account = $this->signInAccount(new FormTypeSupport);
        $id = DB::table('user_credentials')->insertGetId(['user_id' => $account->getKey(), 'type' => 'rogue', 'secret' => Crypt::encryptString('step-1')]);
        $this->app->make(CredentialTypes::class)->register(new RogueType(function () use ($id) {
            DB::table('user_credentials')->where('id', $id)->update(['secret' => Crypt::encryptString('step-2')]);

            return Proof::advanced(new StoredCredential($id, identifier: null, secret: 'step-1', label: null), 'step-2');
        }, surfaces: ['sign-in']));
        demandSudo($this);

        $response = $this->post(route('sudo.submit', ['type' => 'rogue']));

        $response->assertRedirectToRoute('sudo')->assertSessionHasErrors(['rogue' => __('keystone::messages.invalid_credential')]);
        $this->get('probe')->assertRedirectToRoute('sudo');
        $this->assertDatabaseHas('user_security_events', ['type' => 'sudo.failed', 'credential_id' => $id, 'reason' => 'keystone.superseded']);
        expect(DB::table('user_credentials')->where('id', $id)->value('last_used_at'))->toBeNull();
    });

    it('fails an answer from an unparseable address like a wrong answer and grants nothing', function () {
        $this->freezeSecond();
        config(['keystone.rate_limits.failed_attempts_per_hour' => 2]);
        $rogue = new RogueType(fn () => throw new LogicException('verify must not run'), surfaces: ['sign-in']);
        $account = $this->signInAccount(new FormTypeSupport);
        $this->app->make(CredentialTypes::class)->register($rogue);
        DB::table('user_credentials')->insert(['user_id' => $account->getKey(), 'type' => 'rogue']);
        demandSudo($this);
        Notification::fake();

        $response = $this->withServerVariables(['REMOTE_ADDR' => 'not-an-ip'])->post(route('sudo.submit', ['type' => 'rogue']), ['secret' => 'anything']);

        $response->assertRedirectToRoute('sudo')->assertSessionHasErrors(['rogue' => __('keystone::messages.invalid_credential')]);
        expect($rogue->calls)->toBe([]);
        $this->get('probe')->assertRedirectToRoute('sudo');
        $this->assertDatabaseHas('user_security_events', ['type' => 'sudo.failed', 'user_id' => $account->getKey(), 'reason' => 'keystone.unbindable_subnet']);
        Notification::assertSentOnDemand(SecurityAlert::class, fn (SecurityAlert $alert) => $alert->type->value === 'sudo.failed');
        $this->travel(7)->seconds();
        $this->withServerVariables(['REMOTE_ADDR' => 'not-an-ip'])->post(route('sudo.submit', ['type' => 'rogue']), ['secret' => 'anything']);
        $this->post(route('sudo.submit', ['type' => 'rogue']), ['secret' => 'anything'])->assertTooManyRequests();
    });

    it('grants nothing when the credential type throws', function () {
        Exceptions::fake();
        $account = $this->signInAccount(new FormTypeSupport);
        $this->app->make(CredentialTypes::class)->register(new RogueType(fn () => throw new RuntimeException('Broken method.'), surfaces: ['sign-in']));
        DB::table('user_credentials')->insert(['user_id' => $account->getKey(), 'type' => 'rogue']);
        demandSudo($this);

        $response = $this->post(route('sudo.submit', ['type' => 'rogue']), ['secret' => 'anything']);

        $response->assertRedirectToRoute('sudo')->assertSessionHasErrors(['rogue' => __('keystone::messages.invalid_credential')]);
        $this->get('probe')->assertRedirectToRoute('sudo');
        $this->assertDatabaseHas('user_security_events', ['type' => 'sudo.failed', 'reason' => 'keystone.verify_failed']);
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Broken method.');
    });

    it('grants nothing when the account\'s row can\'t be locked', function () {
        $this->signInAccount(new FormTypeSupport);
        demandSudo($this);
        $this->withoutExceptionHandling();
        DB::connection()->beforeExecuting(function (string $query, array $bindings, Connection $connection) {
            if ($connection->transactionLevel() > 1 && preg_match('/from .users./', $query) === 1) {
                throw new RuntimeException('The row is locked by another connection.');
            }
        });

        $request = fn () => $this->post(route('sudo.submit', ['type' => 'form']), (new FormTypeSupport)->validProof(Surface::SIGN_IN));

        expect($request)->toThrow(RuntimeException::class, 'The row is locked by another connection.');
        $this->assertDatabaseMissing('user_security_events', ['type' => 'sudo.granted', 'flow' => 'sudo']);
        $this->withExceptionHandling()->get('probe')->assertRedirectToRoute('sudo');
        $this->get(route('sudo'))->assertOk();
    });

    it('rehashes a password that needs it without moving the credential epoch', function () {
        $account = $this->signInAccount(new FormTypeSupport);
        $id = DB::table('user_credentials')->insertGetId(['user_id' => $account->getKey(), 'type' => 'rogue', 'secret' => Crypt::encryptString('old-hash')]);
        $this->app->make(CredentialTypes::class)->register(new RogueType(fn () => Proof::proven(new StoredCredential($id, identifier: null, secret: 'old-hash', label: null), fn () => 'new-hash'), surfaces: ['sign-in']));
        demandSudo($this);

        $this->post(route('sudo.submit', ['type' => 'rogue']))->assertRedirect('/probe');

        expect(Crypt::decryptString(DB::table('user_credentials')->where('id', $id)->value('secret')))->toBe('new-hash');
        $this->assertDatabaseHas('users', ['id' => $account->getKey(), 'credential_epoch' => 0]);
        $this->get('probe')->assertOk();
    });

    it('limits requests to the step', function () {
        $this->freezeSecond();
        $this->signInAccount(new FormTypeSupport);
        demandSudo($this);
        $this->travel(1)->minute();

        foreach (range(1, config()->integer('keystone.rate_limits.requests_per_minute.submit')) as $ignored) {
            $this->post(route('sudo.submit', ['type' => 'form']), (new FormTypeSupport)->rejectedProof(Surface::SIGN_IN))->assertRedirectToRoute('sudo');
        }

        $this->post(route('sudo.submit', ['type' => 'form']), (new FormTypeSupport)->validProof(Surface::SIGN_IN))->assertTooManyRequests();
    });

    it('closes a ceremony slot when the sudo-in-progress that opened it ends', function () {
        $this->freezeSecond();
        $this->signInAccount(new FormTypeSupport);
        demandSudo($this);
        Keystone::guard()->slots()->put('rogue', 'sign-in', 'challenge-bytes', capSeconds: 3600);
        $this->travel(899)->seconds();
        expect(Keystone::guard()->slots()->get('rogue', 'sign-in'))->toBe('challenge-bytes');
        $this->travel(1)->seconds();

        expect(Keystone::guard()->slots()->get('rogue', 'sign-in'))->toBeNull();
    });

    it('keeps a ceremony slot opened after an abandoned sudo-in-progress ran out', function () {
        $this->freezeSecond();
        $this->signInAccount(new FormTypeSupport);
        demandSudo($this);
        $this->travel(900)->seconds();
        $this->get('whoami');

        Keystone::guard()->slots()->put('rogue', 'sign-in', 'challenge-bytes', capSeconds: 300);

        expect(Keystone::guard()->slots()->get('rogue', 'sign-in'))->toBe('challenge-bytes');
    });

    it('closes a ceremony slot when the sudo-in-progress or the sign-in ends, whichever comes first', function (int $absoluteLifetime, int $slotEndsAfter) {
        $this->freezeSecond();
        config(['keystone.session.absolute_lifetime_seconds' => $absoluteLifetime]);
        $this->signInAccount(new FormTypeSupport);
        $this->travel(500)->seconds();
        demandSudo($this);
        Keystone::guard()->slots()->put('rogue', 'sign-in', 'challenge-bytes', capSeconds: 3600);
        $this->travel($slotEndsAfter - 1)->seconds();
        expect(Keystone::guard()->slots()->get('rogue', 'sign-in'))->toBe('challenge-bytes');
        $this->travel(1)->seconds();

        expect(Keystone::guard()->slots()->get('rogue', 'sign-in'))->toBeNull();
    })->with([
        'the sign-in ends first' => [600, 100],
        'the sudo-in-progress ends first' => [2000, 900],
    ]);

    it('refuses an answer whose sudo-in-progress ran out while it was checked, and grants nothing', function () {
        $this->freezeSecond();
        Exceptions::fake();
        $account = $this->signInAccount(new FormTypeSupport);
        $id = DB::table('user_credentials')->insertGetId(['user_id' => $account->getKey(), 'type' => 'rogue', 'secret' => Crypt::encryptString('hash')]);
        $this->app->make(CredentialTypes::class)->register(new RogueType(function () use ($id) {
            $this->travel(900)->seconds();

            return Proof::proven(new StoredCredential($id, identifier: null, secret: 'hash', label: null));
        }, surfaces: ['sign-in']));
        demandSudo($this);

        $response = $this->post(route('sudo.submit', ['type' => 'rogue']));

        $response->assertRedirectToRoute('sudo')->assertSessionHasErrors(['rogue' => __('keystone::messages.invalid_credential')]);
        $this->get('probe')->assertRedirectToRoute('sudo');
        $this->assertDatabaseMissing('user_security_events', ['type' => 'sudo.granted', 'flow' => 'sudo']);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'sudo.failed']);
        expect(DB::table('user_credentials')->where('id', $id)->value('last_used_at'))->toBeNull();
        Exceptions::assertNothingReported();
    });

    it('stamps the last use of the credential that grants, not of a first factor that still owes the challenge', function () {
        $this->freezeSecond();
        $account = $this->createChallengedAccount(new FormTypeSupport('code'));
        $this->passFirstFactor();
        $this->post(route('login.challenge.submit', ['type' => 'code']), (new FormTypeSupport('code'))->validProof(Surface::CHALLENGE));
        $signedInAt = now()->toDateTimeString();
        demandSudo($this);
        $this->travel(5)->minutes();
        $lastUse = fn (string $type) => DB::table('user_credentials')->where('user_id', $account->getKey())->where('type', $type)->value('last_used_at');

        $this->post(route('sudo.submit', ['type' => 'form']), (new FormTypeSupport)->validProof(Surface::SIGN_IN))->assertRedirectToRoute('sudo');

        expect($lastUse('form'))->toBe($signedInAt)
            ->and($lastUse('code'))->toBe($signedInAt);

        $this->post(route('sudo.submit', ['type' => 'code']), (new FormTypeSupport('code'))->validProof(Surface::CHALLENGE))->assertRedirect('/probe');

        expect($lastUse('form'))->toBe($signedInAt)
            ->and($lastUse('code'))->toBe(now()->toDateTimeString());
    });

    it('closes every ceremony slot when it grants', function () {
        $this->signInAccount(new FormTypeSupport);
        demandSudo($this);
        Keystone::guard()->slots()->put('rogue', 'sign-in', 'challenge-bytes', capSeconds: 300);

        $this->post(route('sudo.submit', ['type' => 'form']), (new FormTypeSupport)->validProof(Surface::SIGN_IN));

        expect(session()->has(CeremonySlots::SESSION_KEY))->toBeFalse();
    });
});

describe('the grant a sign-in brings', function () {
    it('records sudo.granted with reason keystone.sign_in when a sign-in completes', function () {
        $account = $this->createFirstFactorAccount();

        $this->submitSignIn(new FormTypeSupport, 'jane@example.com', (new FormTypeSupport)->validProof(Surface::SIGN_IN));

        $granted = SecurityEvent::query()->where('type', 'sudo.granted')->sole();
        expect($granted->user_id)->toEqual($account->getKey())
            ->and($granted->reason)->toBe('keystone.sign_in')
            ->and($granted->credential_type)->toBeNull()
            ->and($granted->credential_id)->toBeNull()
            ->and($granted->known_device)->toBeNull();
    });

    it('brings sudo when a passed challenge signs the session in', function () {
        $account = $this->createChallengedAccount(new FormTypeSupport('code'));
        $this->passFirstFactor();
        $this->assertDatabaseMissing('user_security_events', ['type' => 'sudo.granted']);

        $this->post(route('login.challenge.submit', ['type' => 'code']), (new FormTypeSupport('code'))->validProof(Surface::CHALLENGE));

        $this->assertAuthenticatedAs($account);
        expect(SecurityEvent::query()->where('type', 'sudo.granted')->sole()->reason)->toBe('keystone.sign_in');
    });

    it('brings sudo when an enrollment ends in a sign-in', function () {
        config(['keystone.require_second_factor' => true]);
        $account = $this->createFirstFactorAccount();
        $this->passFirstFactor();
        $this->assertDatabaseMissing('user_security_events', ['type' => 'sudo.granted']);

        $this->enrollSecondFactor(new FormTypeSupport('code'));

        $this->assertAuthenticatedAs($account);
        expect(SecurityEvent::query()->where('type', 'sudo.granted')->sole()->reason)->toBe('keystone.sign_in');
    });
});

describe('ending sudo', function () {
    it('ends sudo, records sudo.revoked, rotates the session id and flashes sudo-revoked', function () {
        $account = $this->signInAccount(new FormTypeSupport);
        $sessionId = session()->getId();

        $response = $this->delete(route('sudo.end'));

        $response->assertRedirectToRoute('security');
        $this->assertAuthenticatedAs($account);
        $revoked = SecurityEvent::query()->where('type', 'sudo.revoked')->sole();
        expect($revoked->user_id)->toEqual($account->getKey())
            ->and($revoked->actor->value)->toBe('user')
            ->and(session()->getId())->not->toBe($sessionId)
            ->and(session('keystone.status'))->toBe('sudo-revoked');
    });

    it('records nothing when there is no live grant to end', function (Closure $lose) {
        $this->freezeSecond();
        $account = $this->signInAccount(new FormTypeSupport);
        $lose($this);
        $sessionId = session()->getId();
        $recorded = SecurityEvent::query()->where('type', 'sudo.revoked')->count();

        $response = $this->delete(route('sudo.end'));

        $response->assertRedirectToRoute('security');
        $this->assertAuthenticatedAs($account);
        expect(SecurityEvent::query()->where('type', 'sudo.revoked')->count())->toBe($recorded)
            ->and(session()->getId())->not->toBe($sessionId)
            ->and(session('keystone.status'))->toBe('sudo-revoked');
    })->with([
        'already ended' => [fn (AppTestCase $test) => $test->delete(route('sudo.end'))],
        'run out after sudo.lifetime_seconds' => [fn (AppTestCase $test) => $test->travel(900)->seconds()],
    ]);

    it('sends a guest away from ending sudo', function () {
        $response = $this->delete(route('sudo.end'));

        $response->assertRedirectToRoute('login');
        $this->assertGuest();
        $this->assertDatabaseCount('user_security_events', 0);
        expect(session('keystone.status'))->toBeNull();
    });

    it('limits ending sudo to the change allowance', function () {
        $this->freezeSecond();
        config(['keystone.rate_limits.requests_per_minute.change' => 2]);
        $this->signInAccount(new FormTypeSupport);
        $this->delete(route('sudo.end'));
        $this->delete(route('sudo.end'));

        $response = $this->delete(route('sudo.end'));

        $response->assertTooManyRequests();
    });

    it('puts the hardening floor on the end-sudo response', function () {
        $this->signInAccount(new FormTypeSupport);

        $response = $this->delete(route('sudo.end'));

        $this->assertHardeningFloor($response);
    });

    it('ends sudo, so the gate refuses again', function () {
        $this->signInAccount(new FormTypeSupport);
        $this->get('probe')->assertOk();

        $this->delete(route('sudo.end'));

        $this->get('probe')->assertRedirectToRoute('sudo');
    });

    it('drops a sudo-in-progress without recording a revoked grant', function () {
        $this->signInAccount(new FormTypeSupport);
        demandSudo($this);
        $this->get(route('sudo'))->assertOk();
        $recorded = SecurityEvent::query()->where('type', 'sudo.revoked')->count();

        $this->delete(route('sudo.end'));

        $this->get(route('sudo'))->assertRedirect('/');
        expect(SecurityEvent::query()->where('type', 'sudo.revoked')->count())->toBe($recorded);
    });
});

describe('the lifetime of a grant', function () {
    it('keeps the grant until fifteen minutes have passed since the sign-in', function () {
        $this->freezeSecond();
        $account = $this->signInAccount(new FormTypeSupport);
        $this->travel(899)->seconds();

        $this->delete(route('sudo.end'));

        $this->assertDatabaseHas('user_security_events', ['type' => 'sudo.revoked', 'user_id' => $account->getKey()]);
    });

    it('never slides the grant', function () {
        $this->freezeSecond();
        $this->signInAccount(new FormTypeSupport);
        $this->travel(899)->seconds();
        $this->get('whoami');
        $this->travel(1)->seconds();

        $this->delete(route('sudo.end'));

        $this->assertDatabaseMissing('user_security_events', ['type' => 'sudo.revoked']);
    });

    it('honours a shorter configured lifetime', function (int $seconds, int $revoked) {
        $this->freezeSecond();
        config(['keystone.sudo.lifetime_seconds' => 60]);
        $this->signInAccount(new FormTypeSupport);
        $this->travel($seconds)->seconds();

        $this->delete(route('sudo.end'));

        expect(SecurityEvent::query()->where('type', 'sudo.revoked')->count())->toBe($revoked);
    })->with([
        'live a second before it runs out' => [59, 1],
        'gone the second it runs out' => [60, 0],
    ]);

    it('keeps the grant across the owner\'s own epoch move', function () {
        $account = $this->signInAccount(new FormTypeSupport);
        $this->post('end-my-sessions');
        $this->assertDatabaseHas('users', ['id' => $account->getKey(), 'credential_epoch' => 1]);

        $this->delete(route('sudo.end'));

        $this->assertAuthenticatedAs($account);
        $this->assertDatabaseHas('user_security_events', ['type' => 'sudo.revoked', 'user_id' => $account->getKey()]);
    });
});

describe('sessions that bring no sudo', function () {
    it('brings no sudo and records nothing on a remembered return', function () {
        $account = $this->createFirstFactorAccount();
        $remember = $this->rememberCookieOf($this->submitSignIn(new FormTypeSupport, 'jane@example.com', [...(new FormTypeSupport)->validProof(Surface::SIGN_IN), 'remember' => '1']));
        session()->invalidate();
        $this->fromRememberCookie($remember);

        $this->get('whoami')->assertContent((string) $account->getKey());
        $this->delete(route('sudo.end'));

        expect(SecurityEvent::query()->where('type', 'sudo.granted')->count())->toBe(1);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'sudo.revoked']);
    });

    it('drops the grant when a remembered return restores an expired session', function () {
        $this->freezeSecond();
        config(['keystone.session.absolute_lifetime_seconds' => 600]);
        $account = $this->createFirstFactorAccount();
        $remember = $this->rememberCookieOf($this->submitSignIn(new FormTypeSupport, 'jane@example.com', [...(new FormTypeSupport)->validProof(Surface::SIGN_IN), 'remember' => '1']));
        $this->fromRememberCookie($remember);
        $this->travel(600)->seconds();

        $this->delete(route('sudo.end'));

        $this->assertAuthenticatedAs($account);
        $this->assertDatabaseHas('user_security_events', ['type' => 'signed_in', 'reason' => 'remembered']);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'sudo.revoked']);
    });

    it('brings no sudo from an address it can\'t parse', function () {
        $account = $this->createFirstFactorAccount();

        $this->withServerVariables(['REMOTE_ADDR' => 'not-an-ip'])->submitSignIn(new FormTypeSupport, 'jane@example.com', (new FormTypeSupport)->validProof(Surface::SIGN_IN));
        $this->delete(route('sudo.end'));

        $this->assertAuthenticatedAs($account);
        $this->assertDatabaseHas('user_security_events', ['type' => 'signed_in', 'user_id' => $account->getKey()]);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'sudo.granted']);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'sudo.revoked']);
    });
});
