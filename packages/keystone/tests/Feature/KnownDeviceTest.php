<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\KnownDevices;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Notifications\SecurityAlert;
use ClaudioDekker\Keystone\SecurityEvent;
use ClaudioDekker\Keystone\SecurityEventType;
use ClaudioDekker\Keystone\Tests\Fixtures\FormTypeSupport;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

pest()->extend(AppTestCase::class);

beforeEach(function () {
    Notification::fake();
});

/**
 * Open a fresh session in a browser holding the device cookie's value, or none.
 */
function openBrowser(AppTestCase $test, ?string $device, string $ip = '203.0.113.1'): void
{
    session()->invalidate();

    $test->fromDevice($device)->withServerVariables(['REMOTE_ADDR' => $ip]);
}

/**
 * Sign the address in from a browser holding the device cookie's value, then sign out, returning the value the browser was handed.
 */
function signInOnDevice(AppTestCase $test, ?string $device, string $ip = '203.0.113.1', string $address = 'jane@example.com'): ?string
{
    openBrowser($test, $device, $ip);

    $response = $test->submitSignIn(new FormTypeSupport, $address, (new FormTypeSupport)->validProof(Surface::SIGN_IN));

    $response->assertRedirect('/');
    $test->post(route('logout'));

    return $test->deviceCookieOf($response);
}

/**
 * Submit the account's valid proof from a browser holding the device cookie's value, or none.
 *
 * @return TestResponse<Response>
 */
function proveOnDevice(AppTestCase $test, ?string $device, string $ip, string $address = 'jane@example.com'): TestResponse
{
    openBrowser($test, $device, $ip);

    return $test->submitSignIn(new FormTypeSupport, $address, (new FormTypeSupport)->validProof(Surface::SIGN_IN));
}

/**
 * Submit wrong answers for the address from a browser holding the device cookie's value, or none, each from its own IP address, returning the last response.
 *
 * @return TestResponse<Response>
 */
function failOnDevice(AppTestCase $test, ?string $device, string $ip, string $address = 'jane@example.com', int $times = 1): TestResponse
{
    foreach (range(1, $times) as $i) {
        openBrowser($test, $device, $i === 1 ? $ip : "{$ip}{$i}");

        $response = $test->submitSignIn(new FormTypeSupport, $address, (new FormTypeSupport)->rejectedProof(Surface::SIGN_IN));
    }

    return $response;
}

/**
 * Count the new-device alerts sent.
 */
function newDeviceAlerts(): int
{
    return Notification::sent(new AnonymousNotifiable, SecurityAlert::class, fn (SecurityAlert $alert) => $alert->type === SecurityEventType::SIGNED_IN)->count();
}

/**
 * Get whether each recorded sign-in came from a known device, oldest first.
 *
 * @return list<bool|null>
 */
function knownDeviceFlags(): array
{
    return SecurityEvent::query()->where('type', SecurityEventType::SIGNED_IN)->orderBy('id')->pluck('known_device')->all();
}

describe('the device cookie', function () {
    it('hands the browser a host-only, secure, http-only, lax cookie the framework does not encrypt, lasting the retention', function () {
        $this->freezeSecond();
        $account = $this->createAccount();
        $this->arrangeCredential($account, new FormTypeSupport, Surface::SIGN_IN);

        $response = $this->submitSignIn(new FormTypeSupport, 'jane@example.com', (new FormTypeSupport)->validProof(Surface::SIGN_IN));

        $cookie = $response->getCookie(KnownDevices::COOKIE, decrypt: false);
        expect($cookie->getValue())->toMatch('/^[0-9a-f]{64}$/')
            ->and($cookie->getDomain())->toBeNull()
            ->and($cookie->getPath())->toBe('/')
            ->and($cookie->isSecure())->toBeTrue()
            ->and($cookie->isHttpOnly())->toBeTrue()
            ->and($cookie->getSameSite())->toBe('lax')
            ->and($cookie->getExpiresTime())->toBe(now()->addSeconds(config()->integer('keystone.retention.known_devices_seconds'))->getTimestamp());
    });

    it('stores only a digest of the value, with the user agent and IP address as encrypted labels', function () {
        $account = $this->createAccount();
        $this->arrangeCredential($account, new FormTypeSupport, Surface::SIGN_IN);
        $this->withHeader('User-Agent', 'Firefox/130.0');

        $device = signInOnDevice($this, null, '198.51.100.7');

        $row = DB::table('user_known_devices')->sole();
        expect($row->user_id)->toEqual($account->getKey())
            ->and($row->cookie_hash)->toBe(hash('sha256', $device))
            ->and($row->user_agent)->not->toContain('Firefox')
            ->and(Crypt::decryptString($row->user_agent))->toBe('Firefox/130.0')
            ->and(Crypt::decryptString($row->ip_address))->toBe('198.51.100.7');
    });

    it('hands out no cookie when the sign-in is refused', function () {
        $account = $this->createAccount();
        $this->arrangeCredential($account, new FormTypeSupport, Surface::SIGN_IN);

        $response = failOnDevice($this, null, '203.0.113.1');

        expect($this->deviceCookieOf($response))->toBeNull();
        $this->assertDatabaseCount('user_known_devices', 0);
    });
});

describe('a sign-in', function () {
    it('alerts the owner when it comes from a browser the account has not signed in from', function () {
        $account = $this->signInAccount(new FormTypeSupport);

        Notification::assertSentOnDemand(SecurityAlert::class, fn (SecurityAlert $alert) => $alert->type === SecurityEventType::SIGNED_IN);
        expect(SecurityEvent::query()->where('type', SecurityEventType::SIGNED_IN)->sole()->only(['user_id', 'known_device']))->toEqual([
            'user_id' => $account->getKey(),
            'known_device' => false,
        ]);
    });

    it('does not alert when it comes from a known device, even on a new IP address', function () {
        $account = $this->createAccount();
        $this->arrangeCredential($account, new FormTypeSupport, Surface::SIGN_IN);
        $device = signInOnDevice($this, null, '203.0.113.1');

        signInOnDevice($this, $device, '198.51.100.1');

        expect(knownDeviceFlags())->toBe([false, true]);
        expect(newDeviceAlerts())->toBe(1);
    });

    it('alerts when a new browser signs in from a known IP address', function () {
        $account = $this->createAccount();
        $this->arrangeCredential($account, new FormTypeSupport, Surface::SIGN_IN);
        signInOnDevice($this, null, '203.0.113.1');

        signInOnDevice($this, null, '203.0.113.1');

        expect(knownDeviceFlags())->toBe([false, false]);
        expect(newDeviceAlerts())->toBe(2);
    });

    it('hands out a fresh value every time, so a copied cookie stops being known at the owner\'s next sign-in', function () {
        $account = $this->createAccount();
        $this->arrangeCredential($account, new FormTypeSupport, Surface::SIGN_IN);
        $copied = signInOnDevice($this, null);

        $fresh = signInOnDevice($this, $copied);
        signInOnDevice($this, $copied, '198.51.100.1');

        expect($fresh)->not->toBe($copied)
            ->and(knownDeviceFlags())->toBe([false, true, false]);
    });

    it('does not count a value it never handed out as known', function () {
        $account = $this->createAccount();
        $this->arrangeCredential($account, new FormTypeSupport, Surface::SIGN_IN);
        signInOnDevice($this, null);

        signInOnDevice($this, str_repeat('a', 64));

        expect(knownDeviceFlags())->toBe([false, false]);
    });

    it('does not count a device only another account knows as known', function () {
        $jane = $this->createAccount('jane@example.com');
        $john = $this->createAccount('john@example.com');
        $this->arrangeCredential($jane, new FormTypeSupport, Surface::SIGN_IN);
        $this->arrangeCredential($john, new FormTypeSupport, Surface::SIGN_IN);
        $device = signInOnDevice($this, null, address: 'john@example.com');

        signInOnDevice($this, $device, address: 'jane@example.com');

        expect(knownDeviceFlags())->toBe([false, false]);
    });

    it('moves every account that knew the browser\'s value on to its new one', function () {
        $jane = $this->createAccount('jane@example.com');
        $john = $this->createAccount('john@example.com');
        $this->arrangeCredential($jane, new FormTypeSupport, Surface::SIGN_IN);
        $this->arrangeCredential($john, new FormTypeSupport, Surface::SIGN_IN);
        $first = signInOnDevice($this, null, address: 'john@example.com');
        $second = signInOnDevice($this, $first, address: 'jane@example.com');

        signInOnDevice($this, $second, address: 'john@example.com');

        expect(knownDeviceFlags())->toBe([false, false, true]);
    });

    it('still counts a device seen just inside the retention as known', function () {
        $account = $this->createAccount();
        $this->arrangeCredential($account, new FormTypeSupport, Surface::SIGN_IN);
        $device = signInOnDevice($this, null);
        $this->travel(config()->integer('keystone.retention.known_devices_seconds') - 1)->seconds();

        signInOnDevice($this, $device);

        expect(knownDeviceFlags())->toBe([false, true]);
    });

    it('counts a device unseen for the retention as new again', function () {
        $account = $this->createAccount();
        $this->arrangeCredential($account, new FormTypeSupport, Surface::SIGN_IN);
        $device = signInOnDevice($this, null);
        $this->travel(config()->integer('keystone.retention.known_devices_seconds'))->seconds();

        signInOnDevice($this, $device);

        expect(knownDeviceFlags())->toBe([false, false]);
    });

    it('tells a known device apart when the challenge completes the sign-in', function () {
        $code = new FormTypeSupport('code');
        $this->createChallengedAccount($code);
        openBrowser($this, null);
        $this->passFirstFactor();
        $device = $this->deviceCookieOf($this->post(route('login.challenge.submit', ['type' => 'code']), $code->validProof(Surface::CHALLENGE)));
        $this->post(route('logout'));
        openBrowser($this, $device);
        $this->passFirstFactor();

        $response = $this->post(route('login.challenge.submit', ['type' => 'code']), $code->validProof(Surface::CHALLENGE));

        $response->assertRedirect('/');
        expect(knownDeviceFlags())->toBe([false, true])
            ->and(newDeviceAlerts())->toBe(1);
    });

    it('tells a known device apart when an enrollment completes the sign-in', function () {
        $this->createFirstFactorAccount();
        $device = signInOnDevice($this, null);
        config(['keystone.require_second_factor' => true]);
        openBrowser($this, $device);

        $this->passFirstFactor();
        $response = $this->enrollSecondFactor(new FormTypeSupport('code'));

        $response->assertRedirect('/');
        expect(knownDeviceFlags())->toBe([false, true]);
        expect(newDeviceAlerts())->toBe(1);
    });
});

describe('failed attempts', function () {
    beforeEach(function () {
        config(['keystone.rate_limits.failed_attempts_per_hour' => 3]);
    });

    it('keeps the owner\'s known device signing in once other browsers spent the account\'s allowance', function () {
        $account = $this->createAccount();
        $this->arrangeCredential($account, new FormTypeSupport, Surface::SIGN_IN);
        $device = signInOnDevice($this, null);
        failOnDevice($this, null, '198.51.100.1', times: 4)->assertTooManyRequests();

        $response = proveOnDevice($this, $device, '198.51.100.9');

        $response->assertRedirect('/');
    });

    it('gives a known device an allowance of its own that other browsers keep after it is spent', function () {
        $account = $this->createAccount();
        $this->arrangeCredential($account, new FormTypeSupport, Surface::SIGN_IN);
        $device = signInOnDevice($this, null);
        failOnDevice($this, $device, '198.51.100.1', times: 4)->assertTooManyRequests();

        $response = proveOnDevice($this, null, '198.51.100.9');

        $response->assertRedirect('/');
    });

    it('keeps a known device\'s spent allowance spent when another account\'s sign-in hands the browser a new value', function () {
        $jane = $this->createAccount('jane@example.com');
        $john = $this->createAccount('john@example.com');
        $this->arrangeCredential($jane, new FormTypeSupport, Surface::SIGN_IN);
        $this->arrangeCredential($john, new FormTypeSupport, Surface::SIGN_IN);
        $johnsDevice = signInOnDevice($this, null, address: 'john@example.com');
        $sharedDevice = signInOnDevice($this, $johnsDevice, address: 'jane@example.com');
        failOnDevice($this, $sharedDevice, '198.51.100.1', times: 4)->assertTooManyRequests();
        $rekeyedDevice = signInOnDevice($this, $sharedDevice, address: 'john@example.com');

        $response = proveOnDevice($this, $rekeyedDevice, '198.51.100.9');

        $response->assertTooManyRequests();
    });

    it('counts a device only another account knows with every other browser', function () {
        $jane = $this->createAccount('jane@example.com');
        $john = $this->createAccount('john@example.com');
        $this->arrangeCredential($jane, new FormTypeSupport, Surface::SIGN_IN);
        $this->arrangeCredential($john, new FormTypeSupport, Surface::SIGN_IN);
        $johnsDevice = signInOnDevice($this, null, address: 'john@example.com');
        failOnDevice($this, null, '198.51.100.1', times: 3);

        $response = proveOnDevice($this, $johnsDevice, '198.51.100.9');

        $response->assertTooManyRequests();
    });

    it('keeps the owner\'s known device answering the challenge once other browsers spent the account\'s allowance', function () {
        $code = new FormTypeSupport('code');
        $this->createChallengedAccount($code);
        openBrowser($this, null);
        $this->passFirstFactor();
        $device = $this->deviceCookieOf($this->post(route('login.challenge.submit', ['type' => 'code']), $code->validProof(Surface::CHALLENGE)));
        $this->post(route('logout'));
        openBrowser($this, null, '198.51.100.1');
        $this->passFirstFactor();

        foreach (range(1, 4) as $ignored) {
            $this->post(route('login.challenge.submit', ['type' => 'code']), $code->rejectedProof(Surface::CHALLENGE));
        }

        openBrowser($this, $device, '198.51.100.9');
        $this->passFirstFactor();

        $response = $this->post(route('login.challenge.submit', ['type' => 'code']), $code->validProof(Surface::CHALLENGE));

        $response->assertRedirect('/');
    });
});

describe('forgetting devices', function () {
    it('forgets devices unseen for the retention, nightly on one server', function () {
        $this->travelTo(now()->startOfDay()->subHour());
        $jane = $this->createAccount('jane@example.com');
        $john = $this->createAccount('john@example.com');
        $this->arrangeCredential($jane, new FormTypeSupport, Surface::SIGN_IN);
        $this->arrangeCredential($john, new FormTypeSupport, Surface::SIGN_IN);
        signInOnDevice($this, null, address: 'jane@example.com');
        $this->travel(config()->integer('keystone.retention.known_devices_seconds'))->seconds();
        signInOnDevice($this, null, address: 'john@example.com');
        $this->travelTo(now()->addDay()->startOfDay());

        $this->artisan('schedule:run')->assertSuccessful();

        $events = collect($this->app->make(Schedule::class)->events())->filter(fn (Event $event) => $event->description === 'keystone:prune-known-devices');
        expect($events)->toHaveCount(1)
            ->and($events->sole()->onOneServer)->toBeTrue()
            ->and(DB::table('user_known_devices')->pluck('user_id')->all())->toEqual([$john->getKey()]);
    });
});
