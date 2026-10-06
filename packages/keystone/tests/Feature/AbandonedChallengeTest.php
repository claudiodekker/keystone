<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Tests\Fixtures\FormTypeSupport;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

pest()->extend(AppTestCase::class);

const CHROME_ON_MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';
const FIREFOX_ON_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) Gecko/20100101 Firefox/130.0';

beforeEach(function () {
    Notification::fake();
});

/**
 * Pass the address's first factor in a fresh session, from a browser holding the device cookie's value, or none.
 *
 * @return TestResponse<Response>
 */
function holdFrom(AppTestCase $test, ?string $device = null, string $ip = '203.0.113.1', string $userAgent = FIREFOX_ON_WINDOWS, string $address = 'jane@example.com'): TestResponse
{
    session()->invalidate();

    $test->fromDevice($device)->withServerVariables(['REMOTE_ADDR' => $ip])->withHeader('User-Agent', $userAgent);

    return $test->passFirstFactor($address);
}

/**
 * Answer the held sign-in's challenge with the account's valid code.
 *
 * @return TestResponse<Response>
 */
function answerChallenge(AppTestCase $test): TestResponse
{
    return $test->post(route('login.challenge.submit', ['type' => 'code']), (new FormTypeSupport('code'))->validProof(Surface::CHALLENGE));
}

describe('a hold at the challenge', function () {
    it('writes a pending challenge with the account, the device and the IP address when the browser is not a known device', function () {
        $this->freezeSecond();
        $account = $this->createChallengedAccount(new FormTypeSupport('code'));

        holdFrom($this, ip: '203.0.113.7', userAgent: FIREFOX_ON_WINDOWS)->assertRedirectToRoute('login.challenge');

        $row = DB::table('user_pending_challenges')->sole();
        expect($row->user_id)->toEqual($account->getKey())
            ->and(Crypt::decryptString($row->user_agent))->toBe(FIREFOX_ON_WINDOWS)
            ->and(Crypt::decryptString($row->ip_address))->toBe('203.0.113.7')
            ->and($row->created_at)->toBe(now()->toDateTimeString());
    });

    it('writes none when the browser is a known device of the account', function () {
        $this->createChallengedAccount(new FormTypeSupport('code'));
        holdFrom($this);
        $device = $this->deviceCookieOf(answerChallenge($this));
        $this->post(route('logout'));

        holdFrom($this, $device)->assertRedirectToRoute('login.challenge');

        $this->assertDatabaseCount('user_pending_challenges', 0);
    });

    it('writes one when the browser is a known device of another account only', function () {
        $this->createChallengedAccount(new FormTypeSupport('code'));
        $john = $this->createChallengedAccount(new FormTypeSupport('code'), 'john@example.com');
        holdFrom($this);
        $device = $this->deviceCookieOf(answerChallenge($this));
        $this->post(route('logout'));

        holdFrom($this, $device, address: 'john@example.com');

        expect(DB::table('user_pending_challenges')->pluck('user_id')->all())->toEqual([$john->getKey()]);
    });

    it('writes none for a sign-in held at enrollment', function () {
        $this->createFirstFactorAccount();
        config(['keystone.require_second_factor' => true]);

        holdFrom($this)->assertRedirectToRoute('login.enrollment');

        $this->assertDatabaseCount('user_pending_challenges', 0);
    });

    it('writes none for a sign-in that owes no challenge', function () {
        $this->createFirstFactorAccount();

        holdFrom($this)->assertRedirect('/');

        $this->assertDatabaseCount('user_pending_challenges', 0);
    });

    it('cuts a long user agent to the length kept everywhere else', function () {
        $this->createChallengedAccount(new FormTypeSupport('code'));

        holdFrom($this, userAgent: str_repeat('a', 600));

        expect(Crypt::decryptString(DB::table('user_pending_challenges')->value('user_agent')))->toBe(str_repeat('a', 512));
    });
});

describe('the pending challenge', function () {
    it('is deleted when the challenge is passed', function () {
        $this->createChallengedAccount(new FormTypeSupport('code'));
        holdFrom($this);

        answerChallenge($this)->assertRedirect('/');

        $this->assertDatabaseCount('user_pending_challenges', 0);
    });

    it('is deleted when a recovery code passes the challenge', function () {
        $account = $this->createChallengedAccount(new FormTypeSupport('code'));
        [$code] = $this->arrangeRecoveryCodes($account);
        holdFrom($this);

        $this->post(route('login.challenge.submit', ['type' => CredentialTypes::RECOVERY_CODE]), ['code' => $code])->assertRedirect('/');

        $this->assertDatabaseCount('user_pending_challenges', 0);
    });

    it('is deleted when the passed challenge leaves the sign-in held at enrollment', function () {
        $this->createChallengedAccount(new FormTypeSupport('code'));
        config(['keystone.require_recovery_codes' => true]);
        holdFrom($this);

        answerChallenge($this)->assertRedirectToRoute('login.enrollment');

        $this->assertGuest();
        $this->assertDatabaseCount('user_pending_challenges', 0);
    });

    it('is kept when the answer is refused', function () {
        $this->createChallengedAccount(new FormTypeSupport('code'));
        holdFrom($this);

        $this->post(route('login.challenge.submit', ['type' => 'code']), (new FormTypeSupport('code'))->rejectedProof(Surface::CHALLENGE));

        $this->assertDatabaseCount('user_pending_challenges', 1);
    });

    it('is kept when the sign-in is cancelled', function () {
        $this->createChallengedAccount(new FormTypeSupport('code'));
        holdFrom($this);

        $this->delete(route('login.challenge.cancel'))->assertRedirectToRoute('login');

        $this->assertDatabaseCount('user_pending_challenges', 1);
    });

    it('is kept when the pending sign-in expires', function () {
        $this->createChallengedAccount(new FormTypeSupport('code'));
        holdFrom($this);
        $this->travel(15)->minutes();

        $this->get(route('login.challenge'))->assertRedirectToRoute('login');

        $this->assertDatabaseCount('user_pending_challenges', 1);
    });

    it('is kept when the pending sign-in is voided', function () {
        $account = $this->createChallengedAccount(new FormTypeSupport('code'));
        holdFrom($this);
        DB::table($account->getTable())->where($account->getKeyName(), $account->getKey())->increment('credential_epoch');

        $this->get(route('login.challenge'))->assertRedirectToRoute('login');

        $this->assertDatabaseCount('user_pending_challenges', 1);
    });

    it('is kept when a newer hold in the same session replaces its sign-in, and only the newer one is deleted on a pass', function () {
        $jane = $this->createChallengedAccount(new FormTypeSupport('code'));
        $this->createChallengedAccount(new FormTypeSupport('code'), 'john@example.com');
        $this->passFirstFactor();
        $this->passFirstFactor('john@example.com');

        answerChallenge($this)->assertRedirect('/');

        expect(DB::table('user_pending_challenges')->pluck('user_id')->all())->toEqual([$jane->getKey()]);
    });

    it('is kept when another account signs in over it in the same session', function () {
        $jane = $this->createChallengedAccount(new FormTypeSupport('code'));
        $john = $this->createFirstFactorAccount('john@example.com');
        $this->passFirstFactor();

        $this->passFirstFactor('john@example.com')->assertRedirect('/');

        $this->assertAuthenticatedAs($john);
        expect(DB::table('user_pending_challenges')->pluck('user_id')->all())->toEqual([$jane->getKey()]);
    });
});
