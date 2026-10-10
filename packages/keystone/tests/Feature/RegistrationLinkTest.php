<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\EmailedLinks;
use ClaudioDekker\Keystone\Hmac;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Notifications\EmailedLinkMail;
use ClaudioDekker\Keystone\Notifications\SecurityAlert;
use ClaudioDekker\Keystone\Registering;
use ClaudioDekker\Keystone\SecurityEventRecorded;
use ClaudioDekker\Keystone\SecurityEventRecorder;
use ClaudioDekker\Keystone\SecurityEventType;
use ClaudioDekker\Keystone\Tests\Fixtures\FormTypeSupport;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Encryption\Encrypter;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Uri;

pest()->extend(AppTestCase::class);

beforeEach(function () {
    $this->freezeSecond();
    Notification::fake();
});

/**
 * Ask for a registration link for the address and get the URL mailed to it.
 */
function mailedRegistrationLink(AppTestCase $test, string $address = 'new@example.com'): string
{
    $test->post(route('register.submit'), ['email' => $address]);

    return Notification::sent(new AnonymousNotifiable, EmailedLinkMail::class, fn ($mail, $channels, $notifiable) => $notifiable->routes['mail'] === $address)->last()->link->url;
}

/**
 * Get the link with one of its query values replaced.
 */
function withQuery(string $url, string $key, string $value): string
{
    return (string) Uri::of($url)->withQuery([$key => $value]);
}

/**
 * Get the request.rejected events recorded so far.
 *
 * @return list<SecurityEventRecorded>
 */
function rejectedLinks(): array
{
    return Event::dispatched(SecurityEventRecorded::class, fn (SecurityEventRecorded $recorded) => $recorded->event->type === SecurityEventType::REQUEST_REJECTED)->flatten()->all();
}

describe('opening a link', function () {
    it('shows a step whose one button posts the link back, changing nothing', function () {
        $url = mailedRegistrationLink($this);
        $sessionId = session()->getId();

        $response = $this->get($url);

        $response->assertOk()->assertExactJson(['page' => 'emailed-link', 'action' => route('register.verify.consume', Uri::of($url)->query()->all(), absolute: false)]);
        expect(session()->getId())->toBe($sessionId)
            ->and(Keystone::guard()->registration())->toBeNull();
        $this->assertDatabaseCount(EmailedLinks::TABLE, 0);
    });

    it('sends no referrer, over the rest of the hardening floor', function () {
        $response = $this->get(mailedRegistrationLink($this));

        $this->assertEmailedLinkHardeningFloor($response);
    });

    it('can be opened again, so a mail scanner spends nothing', function () {
        $url = mailedRegistrationLink($this);
        $this->get($url)->assertOk();

        $this->get($url)->assertOk();
    });

    it('sends a link that no longer works to "link expired", without a referrer', function () {
        $url = mailedRegistrationLink($this);
        $this->travel(EmailedLinks::LIFETIME_SECONDS)->seconds();

        $response = $this->get($url);

        $response->assertRedirectToRoute('register.link-expired')->assertHeader('Referrer-Policy', 'no-referrer');
    });

    it('records request.rejected for a link that no longer works, spending nothing and changing no session', function () {
        Event::fake([SecurityEventRecorded::class]);
        $url = withQuery(mailedRegistrationLink($this), 'signature', str_repeat('0', 64));
        $sessionId = session()->getId();
        $before = session()->except(['_flash', '_previous']);

        $this->get($url)->assertRedirectToRoute('register.link-expired');

        expect(rejectedLinks())->toHaveCount(1)
            ->and(rejectedLinks()[0]->event->reason)->toBe('keystone.link_invalid')
            ->and(session()->getId())->toBe($sessionId)
            ->and(session()->except(['_flash', '_previous']))->toBe($before);
        $this->assertDatabaseCount(EmailedLinks::TABLE, 0);
    });

    it('records nothing for a link that still works', function () {
        Event::fake([SecurityEventRecorded::class]);

        $this->get(mailedRegistrationLink($this))->assertOk();

        expect(rejectedLinks())->toBe([]);
    });

    it('sends a signed-in user away', function () {
        $url = mailedRegistrationLink($this);
        $this->signInAccount(new FormTypeSupport);

        $this->get($url)->assertRedirect('/');
    });

    it('refuses an expiry too large for a timestamp with the generic outcome, recording request.rejected', function (string $method) {
        Event::fake([SecurityEventRecorded::class]);
        $url = withQuery(mailedRegistrationLink($this), 'expires', '99999999999999999999');

        $response = $this->call($method, $url, server: $this->transformHeadersToServerVars(['Sec-Fetch-Site' => 'same-origin']));

        $response->assertRedirectToRoute('register.link-expired');
        expect(rejectedLinks())->toHaveCount(1)
            ->and(rejectedLinks()[0]->event->reason)->toBe('keystone.link_invalid');
    })->with(['opening it' => 'GET', 'spending it' => 'POST']);

    it('refuses a spent link with the generic outcome, recording request.rejected', function () {
        Event::fake([SecurityEventRecorded::class]);
        $url = mailedRegistrationLink($this);
        $this->post($url)->assertRedirectToRoute('register.finish');
        session()->invalidate();

        $response = $this->get($url);

        $response->assertRedirectToRoute('register.link-expired');
        expect(rejectedLinks())->toHaveCount(1)
            ->and(rejectedLinks()[0]->event->reason)->toBe('keystone.link_used');
        $this->assertDatabaseCount(EmailedLinks::TABLE, 1);
    });

    it('posts back only the link\'s own query values', function () {
        $url = withQuery(mailedRegistrationLink($this), 'utm_source', 'newsletter');

        $action = $this->get($url)->json('action');

        expect(Uri::of($action)->query()->all())->toHaveKeys(['expires', 'token', 'signature'])
            ->not->toHaveKey('utm_source');
    });

    it('sends no referrer from the link\'s URL when the request limit refuses it', function () {
        $url = mailedRegistrationLink($this);

        foreach (range(1, config()->integer('keystone.rate_limits.requests_per_minute.view')) as $ignored) {
            $this->get($url);
        }

        $this->get($url)->assertTooManyRequests()->assertHeader('Referrer-Policy', 'no-referrer');
    });

    it('sends no referrer from the link\'s URL while registration is closed', function (string $method) {
        $url = mailedRegistrationLink($this);
        config(['keystone.methods' => ['form']]);

        $response = $this->call($method, $url, server: $this->transformHeadersToServerVars(['Sec-Fetch-Site' => 'same-origin']));

        $response->assertRedirectToRoute('login')->assertHeader('Referrer-Policy', 'no-referrer');
    })->with(['opening it' => 'GET', 'spending it' => 'POST']);

    it('sends no referrer from the link\'s URL to a signed-in user', function () {
        $url = mailedRegistrationLink($this);
        $this->signInAccount(new FormTypeSupport);

        $this->get($url)->assertRedirect('/')->assertHeader('Referrer-Policy', 'no-referrer');
    });

    it('shows the "link expired" step', function () {
        $this->get(route('register.link-expired'))->assertOk()->assertExactJson(['page' => 'register-link-expired']);
    });
});

describe('spending a link', function () {
    it('starts registering the address on a new session id and sends the user on to finish', function () {
        $url = mailedRegistrationLink($this);
        $this->get($url);
        $sessionId = session()->getId();

        $response = $this->post($url);

        $response->assertRedirectToRoute('register.finish')->assertHeader('Referrer-Policy', 'no-referrer');
        expect(session()->getId())->not->toBe($sessionId)
            ->and(Keystone::guard()->registration())->address->toBe('new@example.com')
            ->endsAt->toEqual(now()->addSeconds(Registering::WINDOW_SECONDS)->toImmutable());
    });

    it('drops a pending sign-in, a stale sudo and every ceremony slot', function () {
        $url = mailedRegistrationLink($this);
        $account = $this->createAccount('jane@example.com');
        $this->arrangeCredential($account, new FormTypeSupport, Surface::SIGN_IN);
        $this->arrangeCredential($account, new FormTypeSupport('code'), Surface::CHALLENGE);
        $this->passFirstFactor();
        Keystone::guard()->slots()->put('form', 'challenge', 'bytes', capSeconds: 300);
        session()->put('keystone_sudo_web', ['granted_at' => now()->getTimestamp(), 'subnet' => '127.0.0.0/24']);

        $this->post($url)->assertRedirectToRoute('register.finish');

        expect(Keystone::guard()->pending())->toBeNull()
            ->and(Keystone::guard()->sudoGrant())->toBeNull()
            ->and(Keystone::guard()->slots()->get('form', 'challenge'))->toBeNull();
    });

    it('works once; a replay gets the generic outcome and records request.rejected', function () {
        Event::fake([SecurityEventRecorded::class]);
        $url = mailedRegistrationLink($this);
        $this->post($url)->assertRedirectToRoute('register.finish');
        $this->get(route('register.finish'));
        session()->invalidate();

        $response = $this->post($url);

        $response->assertRedirectToRoute('register.link-expired');
        expect(Keystone::guard()->registration())->toBeNull()
            ->and(rejectedLinks())->toHaveCount(1)
            ->and(rejectedLinks()[0]->event->reason)->toBe('keystone.link_used')
            ->and(rejectedLinks()[0]->event->user_id)->toBeNull();
        $this->assertDatabaseCount(EmailedLinks::TABLE, 1);
    });

    it('refuses a link that doesn\'t hold, with the generic outcome, spending nothing and recording request.rejected', function (Closure $break) {
        Event::fake([SecurityEventRecorded::class]);
        $url = $break->call($this, mailedRegistrationLink($this));

        $response = $this->post($url);

        $response->assertRedirectToRoute('register.link-expired');
        expect(Keystone::guard()->registration())->toBeNull()
            ->and(rejectedLinks())->toHaveCount(1)
            ->and(rejectedLinks()[0]->event->reason)->toBe('keystone.link_invalid');
        $this->assertDatabaseCount(EmailedLinks::TABLE, 0);
    })->with([
        'a tampered signature' => [fn (string $url) => withQuery($url, 'signature', str_repeat('0', 64))],
        'a tampered token' => [fn (string $url) => withQuery($url, 'token', strrev(Uri::of($url)->query()->get('token')))],
        'a tampered expiry' => [fn (string $url) => withQuery($url, 'expires', (string) (now()->getTimestamp() + 60))],
        'no signature' => [fn (string $url) => (string) Uri::of($url)->withoutQuery(['signature'])],
        'expired' => [function (string $url) {
            $this->travel(EmailedLinks::LIFETIME_SECONDS)->seconds();

            return $url;
        }],
        'replayed under a foreign Host' => [fn (string $url) => str_replace('://localhost', '://evil.example', $url)],
        'replayed under another scheme' => [fn (string $url) => str_replace('http://', 'https://', $url)],
        'a payload that doesn\'t decrypt, signed right' => [fn (string $url) => signedRegistrationLink('not encrypted')],
        'an encrypted payload that isn\'t an address' => [fn (string $url) => signedRegistrationLink(registrationEncrypter()->encryptString(json_encode(['address' => ['new@example.com']])))],
        'an encrypted payload that isn\'t JSON' => [fn (string $url) => signedRegistrationLink(registrationEncrypter()->encryptString('new@example.com'))],
        'an address not as Keystone stores it' => [fn (string $url) => signedRegistrationLink(registrationEncrypter()->encryptString(json_encode(['address' => 'New@Example.com'])))],
        'an expiry past a fresh link\'s' => [fn (string $url) => signedRegistrationLink(registrationEncrypter()->encryptString(json_encode(['address' => 'new@example.com'])), now()->addSeconds(EmailedLinks::LIFETIME_SECONDS + 1)->getTimestamp())],
    ]);

    it('takes a link signed right for a fresh payload, as the refusals above assume', function () {
        $url = signedRegistrationLink(registrationEncrypter()->encryptString(json_encode(['address' => 'new@example.com'])));

        $this->post($url)->assertRedirectToRoute('register.finish');
    });

    it('refuses a link signed under a previous app key', function () {
        $url = mailedRegistrationLink($this);
        $previous = config('app.key');
        config(['app.key' => 'base64:'.base64_encode(Encrypter::generateKey('aes-256-cbc')), 'app.previous_keys' => [$previous]]);
        $this->app->forgetInstance('encrypter');

        $this->post($url)->assertRedirectToRoute('register.link-expired');

        expect(Keystone::guard()->registration())->toBeNull();
    });

    it('refuses a link whose address an active account came to hold, after spending it', function (bool $verified) {
        Event::fake([SecurityEventRecorded::class]);
        $url = mailedRegistrationLink($this);
        $this->createAccount('new@example.com', verified: $verified);

        $this->post($url)->assertRedirectToRoute('register.link-expired');

        expect(Keystone::guard()->registration())->toBeNull()
            ->and(rejectedLinks()[0]->event->reason)->toBe('keystone.link_destination');
        $this->assertDatabaseCount(EmailedLinks::TABLE, 1);
    })->with(['verified' => true, 'counted as verified' => false]);

    it('alerts the account that verified the address between the link and its button, creating nothing', function () {
        $url = mailedRegistrationLink($this);
        $owner = $this->createAccount('new@example.com');

        $this->post($url)->assertRedirectToRoute('register.link-expired');

        expect(Keystone::guard()->registration())->toBeNull();
        Notification::assertSentOnDemand(SecurityAlert::class, fn (SecurityAlert $alert, $channels, $notifiable) => $alert->type === SecurityEventType::ADDRESS_CLAIM_ATTEMPTED && $notifiable->routes['mail'] === 'new@example.com');
        $this->assertDatabaseHas('user_security_events', ['type' => 'address.claim_attempted', 'user_id' => $owner->getKey(), 'flow' => 'registration']);
        $this->assertDatabaseCount('users', 1);
    });

    it('records request.rejected beside the alert', function () {
        Event::fake([SecurityEventRecorded::class]);
        $url = mailedRegistrationLink($this);
        $this->createAccount('new@example.com');

        $this->post($url);

        expect(rejectedLinks()[0]->event->reason)->toBe('keystone.link_destination');
        Event::assertDispatched(SecurityEventRecorded::class, fn (SecurityEventRecorded $recorded) => $recorded->event->type === SecurityEventType::ADDRESS_CLAIM_ATTEMPTED);
    });

    it('logs the anonymous refusals of one address once a minute', function () {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$logged) {
            if ($message->message === SecurityEventRecorder::LOG_MESSAGE) {
                $logged[] = $message->context['type'];
            }
        });
        $url = withQuery(mailedRegistrationLink($this), 'signature', str_repeat('0', 64));

        $this->post($url);
        $this->post($url);
        $this->travel(SecurityEventRecorder::ANONYMOUS_LOG_SECONDS)->seconds();
        $this->post($url);

        expect(array_count_values($logged)['request.rejected'])->toBe(2);
    });

    it('sends a signed-in user away without spending the link', function () {
        $url = mailedRegistrationLink($this);
        $this->signInAccount(new FormTypeSupport);

        $this->post($url)->assertRedirect('/');

        $this->assertDatabaseCount(EmailedLinks::TABLE, 0);
    });

    it('takes the submit limit', function () {
        $url = withQuery(mailedRegistrationLink($this), 'signature', str_repeat('0', 64));

        foreach (range(1, config()->integer('keystone.rate_limits.requests_per_minute.submit')) as $ignored) {
            $this->post($url)->assertRedirectToRoute('register.link-expired');
        }

        $this->post($url)->assertTooManyRequests();
    });
});

describe('the finish page', function () {
    it('shows the proven address and the types serving registration', function () {
        $this->post(mailedRegistrationLink($this));

        $response = $this->get(route('register.finish'));

        $response->assertOk()->assertExactJson(['page' => 'register-finish', 'address' => 'new@example.com', 'types' => [['type' => 'password', 'shape' => 'form']], 'status' => null]);
        $this->assertHardeningFloor($response);
    });

    it('sends a session that proved no address back to register', function () {
        $this->get(route('register.finish'))->assertRedirectToRoute('register');
    });

    it('sends the session back to register once 30 minutes have passed since the link was spent', function () {
        $this->post(mailedRegistrationLink($this));

        $this->travel(Registering::WINDOW_SECONDS - 1)->seconds();
        $this->get(route('register.finish'))->assertOk();

        $this->travel(1)->second();
        $this->get(route('register.finish'))->assertRedirectToRoute('register');
    });

    it('sends a signed-in user away', function () {
        $this->signInAccount(new FormTypeSupport);

        $this->get(route('register.finish'))->assertRedirect('/');
    });
});

describe('pruning spent links', function () {
    it('forgets each spent link once it expires, hourly on one server', function () {
        $this->post(mailedRegistrationLink($this, 'early@example.com'));
        $this->travel(5)->minutes();
        $this->post(mailedRegistrationLink($this, 'late@example.com'));
        $prune = collect($this->app->make(Schedule::class)->events())->sole(fn (ScheduledEvent $event) => $event->description === 'keystone:prune-used-email-links');

        $this->travel(EmailedLinks::LIFETIME_SECONDS - 5 * 60)->seconds();
        $prune->run($this->app);

        expect($prune->expression)->toBe('0 * * * *')
            ->and($prune->onOneServer)->toBeTrue()
            ->and(DB::table(EmailedLinks::TABLE)->count())->toBe(1);
    });

    it('keeps a spent link spent until it expires, whatever the app\'s timezone', function () {
        $timezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Amsterdam');
        config(['app.timezone' => 'Europe/Amsterdam']);

        try {
            $url = mailedRegistrationLink($this);
            $this->post($url)->assertRedirectToRoute('register.finish');
            $this->travel(5)->minutes();
            collect($this->app->make(Schedule::class)->events())->sole(fn (ScheduledEvent $event) => $event->description === 'keystone:prune-used-email-links')->run($this->app);
            $this->app['session.store']->invalidate();

            $this->post($url)->assertRedirectToRoute('register.link-expired');
        } finally {
            date_default_timezone_set($timezone);
        }
    });
});

describe('an app under a path', function () {
    beforeEach(function () {
        config(['app.url' => 'http://localhost/app']);
        $this->withServerVariables(['SCRIPT_NAME' => '/app/index.php', 'SCRIPT_FILENAME' => '/app/index.php', 'PHP_SELF' => '/app/index.php']);
    });

    it('mails a link under app.url\'s path, which works there', function () {
        $this->post('http://localhost/app/register', ['email' => 'new@example.com'])->assertRedirect('http://localhost/app/register/link-sent');
        $url = $this->mailedLinkTo('new@example.com');

        $response = $this->post($url);

        expect($url)->toStartWith('http://localhost/app/register/verify?');
        $response->assertRedirect('http://localhost/app/register/finish');
    });
});

/**
 * Get the encrypter registration links' payloads are encrypted with.
 */
function registrationEncrypter(): Encrypter
{
    return new Encrypter(Hmac::subkey('keystone.emailed-link.payload.registration'), 'aes-256-gcm');
}

/**
 * Get a registration link carrying the token, signed as Keystone signs one.
 */
function signedRegistrationLink(string $token, ?int $expires = null): string
{
    $origin = rtrim((string) config('app.url'), '/');
    $path = route('register.verify', absolute: false);
    $expires = (string) ($expires ?? now()->addSeconds(EmailedLinks::LIFETIME_SECONDS)->getTimestamp());
    $signature = Hmac::make('keystone.emailed-link.sign.registration', json_encode([$origin, $path, $expires, $token]));

    return (string) Uri::of($origin.$path)->withQuery(['expires' => $expires, 'token' => $token, 'signature' => $signature]);
}
