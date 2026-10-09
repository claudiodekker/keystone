<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Notifications\SecurityAlert;
use ClaudioDekker\Keystone\SecurityEventType;
use ClaudioDekker\Keystone\Tests\Fixtures\FormTypeSupport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;

pest()->extend(AppTestCase::class);

beforeEach(function () {
    $this->withoutMandates();
    config(['keystone.methods' => ['form', 'code']]);
    Route::middleware('web')->get('who-is-signed-in', fn () => (string) (auth()->id() ?? 'guest'));
});

/**
 * Sign the address in from the named browser, ticking remember-me, and keep the remember-me cookie it was handed.
 */
function signInFromBrowser(AppTestCase $test, string $browser, string $address = 'jane@example.com'): void
{
    $test->inBrowser($browser, function () use ($test, $address) {
        $response = $test->post(route('login.submit', ['type' => 'form']), [
            'identifier' => $address,
            ...(new FormTypeSupport)->validProof(Surface::SIGN_IN),
            'remember' => '1',
        ]);

        $test->fromRememberCookie($test->rememberCookieOf($response));
    });
}

/**
 * Get who the named browser is signed in as on its next request, or "guest".
 */
function signedInFromBrowser(AppTestCase $test, string $browser): string
{
    return $test->inBrowser($browser, fn () => $test->get('who-is-signed-in')->getContent());
}

describe('the confirm step', function () {
    it('shows the step', function () {
        $this->signInAccount(new FormTypeSupport);

        $response = $this->get(route('security.sessions.others.revoke'));

        $response->assertOk()->assertExactJson(['page' => 'sign-out-others']);
    });

    it('asks for sudo first', function () {
        $this->signInAccount(new FormTypeSupport);
        $this->delete(route('sudo.end'));

        $response = $this->get(route('security.sessions.others.revoke'));

        $response->assertRedirectToRoute('sudo');
        expect(Keystone::guard()->sudoInProgress()->intendedUrl)->toBe(route('security.sessions.others.revoke', absolute: false));
    });

    it('sends a guest to sign in', function () {
        $this->get(route('security.sessions.others.revoke'))->assertRedirectToRoute('login');
    });

    it('limits requests to the page', function () {
        $this->signInAccount(new FormTypeSupport);

        foreach (range(1, config()->integer('keystone.rate_limits.requests_per_minute.view')) as $ignored) {
            $this->get(route('security.sessions.others.revoke'))->assertOk();
        }

        $this->get(route('security.sessions.others.revoke'))->assertTooManyRequests();
    });
});

describe('signing out the other sessions', function () {
    it('records it, alerts the owner and says so on the security page', function () {
        $account = $this->signInAccount(new FormTypeSupport);
        Notification::fake();

        $response = $this->delete(route('security.sessions.others.revoke.submit'));

        $response->assertRedirectToRoute('security');
        $this->assertDatabaseHas('user_security_events', [
            'type' => 'sessions.revoked_others',
            'user_id' => $account->getKey(),
            'actor' => 'user',
        ]);
        Notification::assertSentOnDemandTimes(SecurityAlert::class, 1);
        Notification::assertSentOnDemand(SecurityAlert::class, fn (SecurityAlert $alert) => $alert->type === SecurityEventType::SESSIONS_REVOKED_OTHERS);
        $this->get(route('security'))->assertJsonPath('status', __('keystone::messages.status.other-sessions-revoked'));
    });

    it('moves the account\'s credential epoch', function () {
        $account = $this->signInAccount(new FormTypeSupport);
        $epoch = $account->fresh()->getRawOriginal('credential_epoch');

        $this->delete(route('security.sessions.others.revoke.submit'));

        expect($account->fresh()->getRawOriginal('credential_epoch'))->toBe($epoch + 1);
    });

    it('signs out the other browser on its next request, remember-me cookie and all, and keeps this one signed in with its sudo', function (string $driver) {
        $this->freezeSecond();
        $this->useSessionDriver($driver);
        $account = $this->signInAccount(new FormTypeSupport);
        signInFromBrowser($this, 'phone');
        $sessionId = session()->getId();

        $this->delete(route('security.sessions.others.revoke.submit'));

        expect(signedInFromBrowser($this, 'phone'))->toBe('guest')
            ->and(signedInFromBrowser($this, 'phone'))->toBe('guest')
            ->and(session()->getId())->not->toBe($sessionId);
        $this->get(route('security'))->assertOk()->assertJsonPath('sudoEndsAt', now()->addMinutes(15)->toIso8601String());
        $this->assertAuthenticatedAs($account);
    })->with(['array', 'file', 'database']);

    it('deletes the other sessions\' rows on the database driver, and stores this session under its new id', function () {
        $this->useSessionDriver('database');
        $account = $this->signInAccount(new FormTypeSupport);
        signInFromBrowser($this, 'phone');
        signInFromBrowser($this, 'tablet');
        $others = DB::table('sessions')->where('user_id', $account->getKey())->where('id', '!=', session()->getId())->pluck('id')->all();

        $this->delete(route('security.sessions.others.revoke.submit'));

        expect($others)->toHaveCount(2)
            ->and(DB::table('sessions')->where('user_id', $account->getKey())->pluck('id')->all())->toBe([session()->getId()]);
        $this->get(route('security'))->assertOk();
        $this->assertAuthenticatedAs($account);
    });

    it('leaves another account\'s sessions alone', function () {
        $this->useSessionDriver('database');
        $this->signInAccount(new FormTypeSupport);
        $john = $this->createAccount('john@example.com');
        $this->arrangeCredential($john, new FormTypeSupport, Surface::SIGN_IN);
        signInFromBrowser($this, 'johns-laptop', 'john@example.com');
        $johns = DB::table('sessions')->where('user_id', $john->getKey())->value('id');

        $this->delete(route('security.sessions.others.revoke.submit'));

        $this->assertDatabaseHas('sessions', ['id' => $johns, 'user_id' => $john->getKey()]);
        $this->assertDatabaseHas('users', ['id' => $john->getKey(), 'credential_epoch' => 0]);
        expect(signedInFromBrowser($this, 'johns-laptop'))->toBe((string) $john->getKey());
    });

    it('asks for sudo first, signing nothing out', function () {
        $account = $this->signInAccount(new FormTypeSupport);
        $this->delete(route('sudo.end'));

        $response = $this->from(route('security.sessions.others.revoke'))->delete(route('security.sessions.others.revoke.submit'));

        $response->assertRedirectToRoute('sudo');
        $this->assertDatabaseHas('users', ['id' => $account->getKey(), 'credential_epoch' => 0]);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'sessions.revoked_others']);
    });

    it('sends a guest to sign in', function () {
        $this->delete(route('security.sessions.others.revoke.submit'))->assertRedirectToRoute('login');
    });

    it('takes the change limit', function () {
        $this->signInAccount(new FormTypeSupport);

        foreach (range(1, config()->integer('keystone.rate_limits.requests_per_minute.change')) as $ignored) {
            $this->delete(route('security.sessions.others.revoke.submit'))->assertRedirectToRoute('security');
        }

        $this->delete(route('security.sessions.others.revoke.submit'))->assertTooManyRequests();
    });
});

describe('the offer after an enrollment', function () {
    it('offers to sign out the other sessions after an enrollment from the settings', function () {
        $this->signInAccount(new FormTypeSupport);
        $this->get(route('security.enroll', ['type' => 'code']));

        $this->post(route('security.enroll.submit', ['type' => 'code']), ['secret' => $this->enrollmentCeremony('code')]);

        $this->get(route('security'))->assertJsonPath('status', __('keystone::messages.status.enrolled'))->assertJsonPath('offersSignOutOthers', true);
        $this->get(route('security'))->assertJsonPath('offersSignOutOthers', false);
    });

    it('offers nothing with any other status', function () {
        $this->signInAccount(new FormTypeSupport);

        $this->delete(route('security.sessions.others.revoke.submit'));

        $this->get(route('security'))->assertJsonPath('status', __('keystone::messages.status.other-sessions-revoked'))->assertJsonPath('offersSignOutOthers', false);
    });

    it('offers it on the database driver only while another session of the account may be signed in', function (bool $phone) {
        $this->useSessionDriver('database');
        $this->signInAccount(new FormTypeSupport);
        $this->get(route('security.enroll', ['type' => 'code']));

        if ($phone) {
            signInFromBrowser($this, 'phone');
        }

        $this->post(route('security.enroll.submit', ['type' => 'code']), ['secret' => $this->enrollmentCeremony('code')]);

        $this->get(route('security'))->assertJsonPath('offersSignOutOthers', $phone);
    })->with([
        'another browser signed in' => [true],
        'no other browser' => [false],
    ]);

    it('offers nothing on the database driver for a session an earlier epoch move ended', function () {
        $this->useSessionDriver('database');
        $account = $this->signInAccount(new FormTypeSupport);
        signInFromBrowser($this, 'phone');
        $credential = DB::table('user_credentials')->insertGetId(['user_id' => $account->getKey(), 'type' => 'code']);
        $this->travel(1)->minute();
        $this->delete(route('security.credentials.remove.submit', ['credential' => $credential]));
        $this->get(route('security.enroll', ['type' => 'code']));

        $this->post(route('security.enroll.submit', ['type' => 'code']), ['secret' => $this->enrollmentCeremony('code')]);

        $this->assertDatabaseCount('sessions', 2);
        $this->get(route('security'))->assertJsonPath('offersSignOutOthers', false);
    });

    it('offers nothing on the database driver for a session idle past the session lifetime', function () {
        config(['session.lifetime' => 5]);
        $this->useSessionDriver('database');
        $this->signInAccount(new FormTypeSupport);
        signInFromBrowser($this, 'phone');
        $this->travel(4)->minutes();
        $this->get(route('security.enroll', ['type' => 'code']));
        $this->travel(2)->minutes();

        $this->post(route('security.enroll.submit', ['type' => 'code']), ['secret' => $this->enrollmentCeremony('code')]);

        $this->assertDatabaseCount('sessions', 2);
        $this->get(route('security'))->assertJsonPath('status', __('keystone::messages.status.enrolled'))->assertJsonPath('offersSignOutOthers', false);
    });
});
