<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\Jobs\EndSessions;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\SecurityEvent;
use ClaudioDekker\Keystone\Tests\Fixtures\FormTypeSupport;
use Illuminate\Support\Facades\Route;

pest()->extend(AppTestCase::class);

beforeEach(function () {
    $this->withoutMandates();
    Route::middleware('web')->get('whoami', fn () => (string) (auth()->id() ?? 'guest'));
    Route::middleware(['web', 'auth'])->post('end-my-sessions', fn () => EndSessions::dispatchSync(auth()->user()));
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

        $response->assertRedirect('/');
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

        $response->assertRedirect('/');
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
