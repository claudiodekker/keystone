<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\RememberTokens;
use ClaudioDekker\Keystone\Tests\Fixtures\FormTypeSupport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

pest()->extend(AppTestCase::class);

beforeEach(function () {
    Notification::fake();
    Route::middleware('web')->get('whoami', fn () => (string) (auth()->id() ?? 'guest'));
    Route::middleware(['web', 'auth'])->get('dashboard', fn () => 'The dashboard.');
});

/**
 * Submit the address's valid first factor with the remember-me box ticked, or posting the given value for it.
 *
 * @return TestResponse<Response>
 */
function signInTicked(AppTestCase $test, string $address = 'jane@example.com', mixed $remember = '1'): TestResponse
{
    return $test->post(route('login.submit', ['type' => 'form']), [
        'identifier' => $address,
        ...(new FormTypeSupport)->validProof(Surface::SIGN_IN),
        'remember' => $remember,
    ]);
}

/**
 * Create an account that signs in with one factor, and sign it in with the box ticked, returning the remember-me cookie's value.
 */
function rememberOnThisBrowser(AppTestCase $test, string $address = 'jane@example.com'): string
{
    $test->createFirstFactorAccount($address);

    return $test->rememberCookieOf(signInTicked($test, $address));
}

describe('a completed sign-in', function () {
    it('hands a ticked sign-in a host-only, secure, http-only, lax cookie of 64 hex characters that the framework does not encrypt', function () {
        $this->createFirstFactorAccount();

        $response = signInTicked($this);

        $cookie = $response->getCookie(RememberTokens::COOKIE, decrypt: false);
        expect($cookie->getName())->toBe('__Host-keystone_remember')
            ->and($cookie->getValue())->toMatch('/^[0-9a-f]{64}$/')
            ->and($cookie->getDomain())->toBeNull()
            ->and($cookie->getPath())->toBe('/')
            ->and($cookie->isSecure())->toBeTrue()
            ->and($cookie->isHttpOnly())->toBeTrue()
            ->and($cookie->getSameSite())->toBe('lax');
    });

    it('stores only a digest of the value, with the credential epoch and an expiry the cookie shares', function () {
        $this->freezeSecond();
        config(['keystone.remember.lifetime_seconds' => 600]);
        $account = $this->createFirstFactorAccount();
        DB::table('users')->update(['credential_epoch' => 7]);

        $response = signInTicked($this);

        $row = DB::table('user_remember_tokens')->sole();
        expect($row->user_id)->toEqual($account->getKey())
            ->and($row->token_hash)->toBe(hash('sha256', $this->rememberCookieOf($response)))
            ->and($row->credential_epoch)->toEqual(7)
            ->and(strtotime($row->expires_at))->toBe(now()->addSeconds(600)->getTimestamp())
            ->and($response->getCookie(RememberTokens::COOKIE, decrypt: false)->getExpiresTime())->toBe(now()->addSeconds(600)->getTimestamp());
    });

    it('keeps the id of the token it issued in the session', function () {
        $this->createFirstFactorAccount();

        signInTicked($this);

        expect(session('keystone_remember_web'))->toBe(DB::table('user_remember_tokens')->value('id'));
    });

    it('issues nothing when the box was not ticked', function () {
        $account = $this->createFirstFactorAccount();

        $response = $this->passFirstFactor();

        $this->assertAuthenticatedAs($account);
        $this->assertDatabaseCount('user_remember_tokens', 0);
        expect($this->rememberCookieOf($response))->toBeNull()
            ->and(session('keystone_remember_web'))->toBeNull();
    });

    it('signs in without remembering when the posted value is not a tick', function (mixed $remember) {
        $account = $this->createFirstFactorAccount();

        $response = signInTicked($this, remember: $remember);

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($account);
        $this->assertDatabaseCount('user_remember_tokens', 0);
        expect($this->rememberCookieOf($response))->toBeNull();
    })->with(['zero' => ['0'], 'an empty string' => [''], 'a word' => ['always'], 'a list' => [['1']]]);

    it('issues nothing when the sign-in is refused', function () {
        $this->createFirstFactorAccount();

        $response = $this->post(route('login.submit', ['type' => 'form']), [
            'identifier' => 'jane@example.com',
            ...(new FormTypeSupport)->rejectedProof(Surface::SIGN_IN),
            'remember' => '1',
        ]);

        $this->assertGuest();
        $this->assertDatabaseCount('user_remember_tokens', 0);
        expect($this->rememberCookieOf($response))->toBeNull();
    });

    it('issues nothing while the sign-in is held, and remembers it once the challenge completes it', function () {
        $code = new FormTypeSupport('code');
        $account = $this->createChallengedAccount($code);

        $held = signInTicked($this);

        $held->assertRedirectToRoute('login.challenge');
        $this->assertDatabaseCount('user_remember_tokens', 0);
        expect($this->rememberCookieOf($held))->toBeNull();

        $response = $this->post(route('login.challenge.submit', ['type' => 'code']), $code->validProof(Surface::CHALLENGE));

        $this->assertAuthenticatedAs($account);
        expect(DB::table('user_remember_tokens')->sole()->token_hash)->toBe(hash('sha256', $this->rememberCookieOf($response)))
            ->and(session('keystone_remember_web'))->toBe(DB::table('user_remember_tokens')->value('id'));
    });

    it('does not remember a challenged sign-in whose box was not ticked, whatever the challenge posts', function () {
        $code = new FormTypeSupport('code');
        $account = $this->createChallengedAccount($code);
        $this->passFirstFactor();

        $response = $this->post(route('login.challenge.submit', ['type' => 'code']), [...$code->validProof(Surface::CHALLENGE), 'remember' => '1']);

        $this->assertAuthenticatedAs($account);
        $this->assertDatabaseCount('user_remember_tokens', 0);
        expect($this->rememberCookieOf($response))->toBeNull();
    });

    it('remembers a ticked sign-in once the enrollment it was held for completes it', function () {
        config(['keystone.require_second_factor' => true]);
        $account = $this->createFirstFactorAccount();

        $held = signInTicked($this);

        $held->assertRedirectToRoute('login.enrollment');
        $this->assertDatabaseCount('user_remember_tokens', 0);

        $response = $this->enrollSecondFactor(new FormTypeSupport('code'));

        $this->assertAuthenticatedAs($account);
        expect(DB::table('user_remember_tokens')->sole()->token_hash)->toBe(hash('sha256', $this->rememberCookieOf($response)));
    });

    it('remembers a ticked sign-in that passes the challenge and then saves the recovery codes it owes', function () {
        $code = new FormTypeSupport('code');
        $account = $this->createChallengedAccount($code);
        config(['keystone.require_recovery_codes' => true]);
        signInTicked($this);
        $this->post(route('login.challenge.submit', ['type' => 'code']), $code->validProof(Surface::CHALLENGE));
        $this->assertDatabaseCount('user_remember_tokens', 0);
        $this->get(route('login.recovery-codes'));

        $response = $this->post(route('login.recovery-codes.submit'), ['code' => $this->stagedRecoveryCodes()[0]]);

        $this->assertAuthenticatedAs($account);
        expect(DB::table('user_remember_tokens')->sole()->token_hash)->toBe(hash('sha256', $this->rememberCookieOf($response)));
    });
});

describe('remember-me turned off', function () {
    beforeEach(function () {
        config(['keystone.remember.lifetime_seconds' => 0]);
    });

    it('ignores a ticked box', function () {
        $account = $this->createFirstFactorAccount();

        $response = signInTicked($this);

        $this->assertAuthenticatedAs($account);
        $this->assertDatabaseCount('user_remember_tokens', 0);
        expect($this->rememberCookieOf($response))->toBeNull()
            ->and(session('keystone_remember_web'))->toBeNull();
    });

    it('tells the sign-in page not to offer the box', function () {
        $response = $this->get(route('login'));

        $response->assertJsonPath('rememberOffered', false);
    });
});

describe('the sign-in page', function () {
    it('is told to offer the box while remember-me is on', function () {
        $response = $this->get(route('login'));

        $response->assertJsonPath('rememberOffered', true);
    });
});

describe('a sign-out', function () {
    it('forgets this browser\'s token and drops its cookie, leaving the account\'s other devices theirs', function () {
        $account = $this->createFirstFactorAccount();
        signInTicked($this);
        $phone = DB::table('user_remember_tokens')->value('id');
        session()->invalidate();
        $laptop = $this->rememberCookieOf(signInTicked($this));
        $this->fromRememberCookie($laptop);

        $response = $this->post(route('logout'));

        $response->assertCookieExpired(RememberTokens::COOKIE);
        $cookie = $response->getCookie(RememberTokens::COOKIE, decrypt: false);
        expect(DB::table('user_remember_tokens')->pluck('id')->all())->toBe([$phone])
            ->and($cookie->getDomain())->toBeNull()
            ->and($cookie->getPath())->toBe('/')
            ->and($cookie->isSecure())->toBeTrue()
            ->and($cookie->isHttpOnly())->toBeTrue()
            ->and($cookie->getSameSite())->toBe('lax');
    });

    it('sets no cookie in a browser that holds none', function () {
        $this->signInAccount(new FormTypeSupport);

        $response = $this->post(route('logout'));

        expect($response->getCookie(RememberTokens::COOKIE, decrypt: false))->toBeNull();
    });
});
