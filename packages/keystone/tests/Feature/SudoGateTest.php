<?php

use ClaudioDekker\Keystone\Actions\RespondToSudoRequired;
use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Notifications\SecurityAlert;
use ClaudioDekker\Keystone\PendingStage;
use ClaudioDekker\Keystone\SecurityEvent;
use ClaudioDekker\Keystone\SudoGate;
use ClaudioDekker\Keystone\Tests\Fixtures\FormTypeSupport;
use ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers\GatedProbeController;
use ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers\OverridingGatedProbeController;
use ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers\UnguardedGatedProbeController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

pest()->extend(AppTestCase::class);

beforeEach(function () {
    $this->withoutMandates();
    Route::middleware(['web', 'sudo'])->match(['get', 'post'], 'probe', fn () => 'the gated page');
    Route::middleware('web')->delete('gated', [GatedProbeController::class, 'destroy']);
    Route::middleware('web')->delete('gated/overridden', [OverridingGatedProbeController::class, 'destroy']);
    Route::middleware('web')->delete('gated/unguarded', [UnguardedGatedProbeController::class, 'destroy']);
    Route::middleware('web')->post('base-logout', fn () => auth()->logout());
    Route::middleware('web')->get('whoami', fn () => (string) (auth()->id() ?? 'guest'));
    Route::middleware('web')->get('probe-twice', function (Request $request) {
        (new SudoGate(Keystone::guard()))->enforce($request);
        (new SudoGate(Keystone::guard()))->enforce($request);

        return 'the gated page, checked twice';
    });
});

describe('the doors', function () {
    it('lets a session through that just signed in', function (Closure $signIn) {
        $signIn($this);

        $response = $this->get('probe');

        $this->assertAuthenticated();
        $response->assertOk()->assertContent('the gated page');
    })->with([
        'by its first factor alone' => [fn (AppTestCase $test) => $test->signInAccount(new FormTypeSupport)],
        'by passing the challenge' => [function (AppTestCase $test) {
            $test->createChallengedAccount(new FormTypeSupport('code'));
            $test->passFirstFactor();
            $test->post(route('login.challenge.submit', ['type' => 'code']), (new FormTypeSupport('code'))->validProof(Surface::CHALLENGE));
        }],
        'by finishing an enrollment' => [function (AppTestCase $test) {
            config(['keystone.require_second_factor' => true]);
            $test->createFirstFactorAccount();
            $test->passFirstFactor();
            $test->enrollSecondFactor(new FormTypeSupport('code'));
        }],
    ]);

    it('sends a browser without sudo to the sudo step', function () {
        $this->signInAccount(new FormTypeSupport);
        $this->delete(route('sudo.end'));

        $response = $this->get('probe');

        $response->assertRedirectToRoute('sudo');
    });

    it('answers a JSON request without sudo with a 403', function () {
        $this->signInAccount(new FormTypeSupport);
        $this->delete(route('sudo.end'));

        $response = $this->getJson('probe');

        $response->assertForbidden()->assertExactJson(['message' => __('keystone::messages.sudo_required'), 'reason' => 'sudo']);
    });

    it('sends a guest to sign-in', function (Closure $request, Closure $assert) {
        $response = $request($this);

        $assert($response);
        $this->assertGuest();
    })->with([
        'a browser' => [fn (AppTestCase $test) => $test->get('probe'), fn ($response) => $response->assertRedirectToRoute('login')],
        'a JSON client' => [fn (AppTestCase $test) => $test->getJson('probe'), fn ($response) => $response->assertUnauthorized()],
    ]);

    it('answers the same when it runs twice in one request', function (Closure $arrange, Closure $assert) {
        $arrange($this);

        $response = $this->get('probe-twice');

        $assert($response);
    })->with([
        'a pass' => [fn (AppTestCase $test) => $test->signInAccount(new FormTypeSupport), fn ($response) => $response->assertOk()->assertContent('the gated page, checked twice')],
        'a refusal' => [function (AppTestCase $test) {
            $test->signInAccount(new FormTypeSupport);
            $test->delete(route('sudo.end'));
        }, fn ($response) => $response->assertRedirectToRoute('sudo')],
        'a revocation, recorded once' => [function (AppTestCase $test) {
            $test->createFirstFactorAccount();
            $test->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])->submitSignIn(new FormTypeSupport, 'jane@example.com', (new FormTypeSupport)->validProof(Surface::SIGN_IN));
            $test->withServerVariables(['REMOTE_ADDR' => '203.0.114.77']);
        }, function ($response) {
            $response->assertRedirectToRoute('sudo');
            expect(SecurityEvent::query()->where('type', 'sudo.network_changed')->count())->toBe(1);
        }],
    ]);

    it('refuses a guest whose session Laravel\'s own logout left the sudo value in', function () {
        $this->signInAccount(new FormTypeSupport);
        $this->post('base-logout');
        expect(session()->has('keystone_phase_web'))->toBeTrue();

        $response = $this->get('probe');

        $response->assertRedirectToRoute('login');
        $this->assertGuest();
    });

    it('answers a refusal the way a swapped RespondToSudoRequired does', function () {
        $this->app->bind(RespondToSudoRequired::class, fn () => new class extends RespondToSudoRequired
        {
            public function handle(Request $request): Response
            {
                return response('the app\'s own sudo answer', 428);
            }
        });
        $this->signInAccount(new FormTypeSupport);
        $this->delete(route('sudo.end'));

        $response = $this->get('probe');

        $response->assertStatus(428)->assertContent('the app\'s own sudo answer');
    });

    it('refuses a gated Keystone action on a route without the sudo middleware', function () {
        $this->signInAccount(new FormTypeSupport);
        $this->delete('gated')->assertOk()->assertContent('the change was made');
        $this->delete(route('sudo.end'));

        $response = $this->delete('gated');

        $response->assertRedirectToRoute('sudo');
    });

    it('refuses a gated Keystone action an app overrode', function () {
        $this->signInAccount(new FormTypeSupport);
        $this->delete('gated/overridden')->assertOk()->assertContent('the app\'s own change was made');
        $this->delete(route('sudo.end'));

        $response = $this->delete('gated/overridden');

        $response->assertRedirectToRoute('sudo');
    });

    it('refuses a gated Keystone action whose controller middleware an app replaced', function () {
        $this->signInAccount(new FormTypeSupport);
        $this->delete('gated/unguarded')->assertOk()->assertContent('the change was made');
        $this->delete(route('sudo.end'));

        $response = $this->delete('gated/unguarded');

        $response->assertRedirectToRoute('sudo');
    });
});

describe('what the gate records', function () {
    it('records nothing when it refuses and nothing when it lets a request through', function () {
        $this->signInAccount(new FormTypeSupport);
        $recorded = SecurityEvent::query()->count();

        $this->get('probe')->assertOk();
        $this->delete(route('sudo.end'));
        $this->get('probe')->assertRedirectToRoute('sudo');
        $this->getJson('probe')->assertForbidden();

        $this->assertDatabaseCount('user_security_events', $recorded + 1);
        $this->assertDatabaseHas('user_security_events', ['type' => 'sudo.revoked']);
    });

    it('leaves the response of a request it let through alone', function () {
        $this->signInAccount(new FormTypeSupport);

        $response = $this->get('probe');

        $response->assertOk()->assertHeaderMissing('X-Frame-Options')->assertHeaderMissing('Content-Security-Policy');
    });

    it('puts the hardening floor on its refusals of a route that isn\'t a Keystone route', function (Closure $arrange, Closure $request) {
        $arrange($this);

        $response = $request($this);

        $this->assertHardeningFloor($response);
    })->with([
        'the redirect' => [fn (AppTestCase $test) => $test->signInAccount(new FormTypeSupport) && $test->delete(route('sudo.end')), fn (AppTestCase $test) => $test->get('probe')],
        'the 403' => [fn (AppTestCase $test) => $test->signInAccount(new FormTypeSupport) && $test->delete(route('sudo.end')), fn (AppTestCase $test) => $test->getJson('probe')],
        'the guest redirect' => [fn (AppTestCase $test) => null, fn (AppTestCase $test) => $test->get('probe')],
        'the guest 401' => [fn (AppTestCase $test) => null, fn (AppTestCase $test) => $test->getJson('probe')],
    ]);
});

describe('the lifetime', function () {
    it('lets a grant through until keystone.sudo.lifetime_seconds have passed since the sign-in, however often it was used', function () {
        $this->freezeSecond();
        $this->signInAccount(new FormTypeSupport);

        foreach (range(1, 3) as $ignored) {
            $this->travel(299)->seconds();
            $this->get('probe')->assertOk();
        }

        $this->travel(2)->seconds();
        $this->get('probe')->assertOk();

        $this->travel(1)->seconds();
        $this->get('probe')->assertRedirectToRoute('sudo');
    });

    it('honours a shorter configured lifetime', function (int $seconds, Closure $assert) {
        $this->freezeSecond();
        config(['keystone.sudo.lifetime_seconds' => 60]);
        $this->signInAccount(new FormTypeSupport);
        $this->travel($seconds)->seconds();

        $response = $this->get('probe');

        $assert($response);
    })->with([
        'live a second before it runs out' => [59, fn ($response) => $response->assertOk()],
        'gone the second it runs out' => [60, fn ($response) => $response->assertRedirectToRoute('sudo')],
    ]);
});

describe('sessions without a grant', function () {
    it('refuses a remembered return and records no grant for it', function () {
        $account = $this->createFirstFactorAccount();
        $remember = $this->rememberCookieOf($this->submitSignIn(new FormTypeSupport, 'jane@example.com', [...(new FormTypeSupport)->validProof(Surface::SIGN_IN), 'remember' => '1']));
        session()->invalidate();
        $this->fromRememberCookie($remember);

        $response = $this->get('probe');

        $response->assertRedirectToRoute('sudo');
        $this->assertAuthenticatedAs($account);
        expect(SecurityEvent::query()->where('type', 'sudo.granted')->count())->toBe(1);
    });

    it('drops the grant of an expired session that a remember-me cookie restores', function () {
        $this->freezeSecond();
        config(['keystone.session.absolute_lifetime_seconds' => 600]);
        $account = $this->createFirstFactorAccount();
        $remember = $this->rememberCookieOf($this->submitSignIn(new FormTypeSupport, 'jane@example.com', [...(new FormTypeSupport)->validProof(Surface::SIGN_IN), 'remember' => '1']));
        $this->fromRememberCookie($remember);
        $this->travel(600)->seconds();

        $response = $this->get('probe');

        $response->assertRedirectToRoute('sudo');
        $this->assertAuthenticatedAs($account);
        $this->assertDatabaseHas('user_security_events', ['type' => 'signed_in', 'reason' => 'remembered']);
    });

    it('refuses a session that signed in from an unparseable address', function () {
        $account = $this->createFirstFactorAccount();
        $this->withServerVariables(['REMOTE_ADDR' => 'not-an-ip'])->submitSignIn(new FormTypeSupport, 'jane@example.com', (new FormTypeSupport)->validProof(Surface::SIGN_IN));

        $response = $this->get('probe');

        $response->assertRedirectToRoute('sudo');
        $this->assertAuthenticatedAs($account);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'sudo.network_changed']);
    });

    it('drops the grant on every auth-level change', function (Closure $change) {
        $this->freezeSecond();
        config(['keystone.session.absolute_lifetime_seconds' => 600]);
        $account = $this->createFirstFactorAccount();
        $remember = $this->rememberCookieOf($this->submitSignIn(new FormTypeSupport, 'jane@example.com', [...(new FormTypeSupport)->validProof(Surface::SIGN_IN), 'remember' => '1']));
        $this->fromRememberCookie($remember);
        $this->get('probe')->assertOk();

        $change($this);

        $response = $this->get('probe');

        $response->assertRedirect();
        $this->assertAuthenticatedAs($account);
        expect($response->headers->get('Location'))->toBe(route('sudo'));
    })->with([
        'ending sudo' => [fn (AppTestCase $test) => $test->delete(route('sudo.end'))],
        'a remembered return of a session past its absolute lifetime' => [fn (AppTestCase $test) => $test->travel(600)->seconds()],
        'a sign-in held on the session' => [function (AppTestCase $test) {
            $test->get('whoami');
            Keystone::guard()->hold(Keystone::guard()->user(), firstFactor: 'form', stage: PendingStage::CHALLENGE, intendedUrl: '/');
        }],
        'a second sign-in on the session, from another subnet' => [function (AppTestCase $test) {
            $test->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])->get('whoami');
            Keystone::guard()->signIn(Keystone::guard()->user());
            $test->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
        }],
    ]);
});

describe('the subnet binding', function () {
    it('stores the grant bound to the subnet the sign-in came from', function () {
        $this->createFirstFactorAccount();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])->submitSignIn(new FormTypeSupport, 'jane@example.com', (new FormTypeSupport)->validProof(Surface::SIGN_IN));

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.1'])->get('probe')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.114.77'])->get('probe')->assertRedirectToRoute('sudo');
    });

    it('lets a grant through from the same subnet', function (string $signedInFrom, string $usedFrom) {
        $this->createFirstFactorAccount();
        $this->withServerVariables(['REMOTE_ADDR' => $signedInFrom])->submitSignIn(new FormTypeSupport, 'jane@example.com', (new FormTypeSupport)->validProof(Surface::SIGN_IN));

        $response = $this->withServerVariables(['REMOTE_ADDR' => $usedFrom])->get('probe');

        $response->assertOk();
    })->with([
        'the same /24' => ['203.0.113.77', '203.0.113.200'],
        'the same /24 as an IPv4-mapped IPv6 address' => ['203.0.113.77', '::ffff:203.0.113.200'],
        'the same /64' => ['2001:db8:0:1:aaaa:bbbb:cccc:dddd', '2001:db8:0:1:1111:2222:3333:4444'],
    ]);

    it('revokes a live grant used from another subnet, records it and alerts the owner', function (string $signedInFrom, string $usedFrom) {
        Notification::fake();
        $account = $this->createFirstFactorAccount();
        $this->withServerVariables(['REMOTE_ADDR' => $signedInFrom])->submitSignIn(new FormTypeSupport, 'jane@example.com', (new FormTypeSupport)->validProof(Surface::SIGN_IN));

        $response = $this->withServerVariables(['REMOTE_ADDR' => $usedFrom])->get('probe');

        $response->assertRedirectToRoute('sudo');
        $this->assertAuthenticatedAs($account);
        $changed = SecurityEvent::query()->where('type', 'sudo.network_changed')->sole();
        expect($changed->user_id)->toEqual($account->getKey())
            ->and($changed->known_device)->toBeNull();
        $this->assertDatabaseMissing('user_security_events', ['type' => 'sudo.revoked']);
        Notification::assertSentOnDemand(SecurityAlert::class, fn (SecurityAlert $alert, array $channels, object $notifiable) => $alert->type->value === 'sudo.network_changed' && $notifiable->routes['mail'] === 'jane@example.com');
    })->with([
        'another /24' => ['203.0.113.77', '203.0.114.77'],
        'another /64' => ['2001:db8:0:1:aaaa:bbbb:cccc:dddd', '2001:db8:0:2:aaaa:bbbb:cccc:dddd'],
        'IPv6 after IPv4' => ['203.0.113.77', '2001:db8:0:1:aaaa:bbbb:cccc:dddd'],
        'an unparseable address' => ['203.0.113.77', 'not-an-ip'],
    ]);

    it('alerts about a network change from a known device too', function () {
        Notification::fake();
        $this->createFirstFactorAccount();
        $device = $this->deviceCookieOf($this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])->submitSignIn(new FormTypeSupport, 'jane@example.com', (new FormTypeSupport)->validProof(Surface::SIGN_IN)));

        $this->fromDevice($device)->withServerVariables(['REMOTE_ADDR' => '203.0.114.77'])->get('probe')->assertRedirectToRoute('sudo');

        Notification::assertSentOnDemand(SecurityAlert::class, fn (SecurityAlert $alert) => $alert->type->value === 'sudo.network_changed');
    });

    it('keeps refusing from the bound subnet once a grant was revoked', function () {
        $this->createFirstFactorAccount();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])->submitSignIn(new FormTypeSupport, 'jane@example.com', (new FormTypeSupport)->validProof(Surface::SIGN_IN));
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.114.77'])->get('probe')->assertRedirectToRoute('sudo');

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])->get('probe');

        $response->assertRedirectToRoute('sudo');
        expect(SecurityEvent::query()->where('type', 'sudo.network_changed')->count())->toBe(1);
    });

    it('rotates the session when it revokes a grant', function () {
        $this->createFirstFactorAccount();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])->submitSignIn(new FormTypeSupport, 'jane@example.com', (new FormTypeSupport)->validProof(Surface::SIGN_IN));
        $sessionId = session()->getId();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.114.77'])->get('probe');

        expect(session()->getId())->not->toBe($sessionId);
    });

    it('keeps the session id when it refuses a session without a grant', function () {
        $this->signInAccount(new FormTypeSupport);
        $this->delete(route('sudo.end'));
        $sessionId = session()->getId();

        $this->get('probe');

        expect(session()->getId())->toBe($sessionId);
    });

    it('challenges an expired grant from another subnet without recording anything', function () {
        $this->freezeSecond();
        $this->createFirstFactorAccount();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])->submitSignIn(new FormTypeSupport, 'jane@example.com', (new FormTypeSupport)->validProof(Surface::SIGN_IN));
        $this->travel(900)->seconds();
        Notification::fake();

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.114.77'])->get('probe');

        $response->assertRedirectToRoute('sudo');
        $this->assertDatabaseMissing('user_security_events', ['type' => 'sudo.network_changed']);
        Notification::assertNothingSent();
    });
});
