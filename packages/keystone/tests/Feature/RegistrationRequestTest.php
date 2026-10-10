<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\EmailedLinks;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Notifications\EmailedLinkMail;
use ClaudioDekker\Keystone\Notifications\SecurityAlert;
use ClaudioDekker\Keystone\RateLimiter;
use ClaudioDekker\Keystone\SecurityEventRecorded;
use ClaudioDekker\Keystone\SecurityEventType;
use ClaudioDekker\Keystone\Status;
use ClaudioDekker\Keystone\Tests\Fixtures\FormTypeSupport;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;

pest()->extend(AppTestCase::class);

beforeEach(function () {
    $this->freezeSecond();
    Notification::fake();
});

/**
 * Get the emailed links mailed to the address.
 *
 * @return list<EmailedLinkMail>
 */
function linksMailedTo(string $address): array
{
    return Notification::sent(new AnonymousNotifiable, EmailedLinkMail::class, fn ($mail, $channels, $notifiable) => $notifiable->routes['mail'] === $address)->values()->all();
}

/**
 * Get the alerts of the type mailed to the address.
 *
 * @return list<SecurityAlert>
 */
function alertsMailedTo(string $address, SecurityEventType $type): array
{
    return Notification::sent(new AnonymousNotifiable, SecurityAlert::class, fn (SecurityAlert $alert, $channels, $notifiable) => $alert->type === $type && $notifiable->routes['mail'] === $address)->values()->all();
}

describe('the register page', function () {
    it('shows the register page to a guest', function () {
        $response = $this->get(route('register'));

        $response->assertOk()->assertExactJson(['page' => 'register', 'status' => null, 'mailsLink' => true]);
        $this->assertHardeningFloor($response);
    });

    it('sends a signed-in user away', function () {
        $this->signInAccount(new FormTypeSupport);

        $this->get(route('register'))->assertRedirect('/');
    });

    it('says whether a link will be mailed', function (bool $required) {
        config(['keystone.email_verification.required' => $required]);

        $response = $this->get(route('register'));

        $response->assertOk()->assertExactJson(['page' => 'register', 'status' => null, 'mailsLink' => $required]);
    })->with(['required' => true, 'not required' => false]);

    it('shows the link-sent page to a guest', function () {
        $this->get(route('register.link-sent'))->assertOk()->assertExactJson(['page' => 'register-link-sent']);
    });
});

describe('a closed registration', function () {
    it('sends every registration step to sign in, saying registration is unavailable, and mails nothing', function (Closure $request) {
        config(['keystone.methods' => ['form']]);

        $response = $request->call($this);

        $response->assertRedirectToRoute('login')->assertSessionHas(Status::SESSION_KEY, Status::REGISTRATION_UNAVAILABLE->value);
        $this->assertHardeningFloor($response);
        Notification::assertNothingSent();
    })->with([
        'the register page' => [fn () => $this->get(route('register'))],
        'the email submission' => [fn () => $this->post(route('register.submit'), ['email' => 'new@example.com'])],
        'the link-sent page' => [fn () => $this->get(route('register.link-sent'))],
        'the link-expired page' => [fn () => $this->get(route('register.link-expired'))],
        'the finish page' => [fn () => $this->get(route('register.finish'))],
    ]);

    it('takes the request limit before refusing', function () {
        config(['keystone.methods' => ['form']]);

        foreach (range(1, config()->integer('keystone.rate_limits.requests_per_minute.start')) as $ignored) {
            $this->post(route('register.submit'), ['email' => 'new@example.com'])->assertRedirectToRoute('login');
        }

        $this->post(route('register.submit'), ['email' => 'new@example.com'])->assertTooManyRequests();
    });

    it('says registration is unavailable on the sign-in page', function () {
        config(['keystone.methods' => ['form']]);
        $this->get(route('register'));

        $this->get(route('login'))->assertJsonPath('status', __('keystone::messages.status.registration-unavailable'));
    });
});

describe('the email submission', function () {
    it('mails a free address a registration link built against app.url, and sends the user on to "link sent"', function () {
        config(['app.url' => 'https://app.example']);

        $response = $this->post('http://localhost'.route('register.submit', absolute: false), ['email' => 'new@example.com']);

        $response->assertRedirectToRoute('register.link-sent');
        $links = linksMailedTo('new@example.com');
        expect($links)->toHaveCount(1)
            ->and($links[0]->link->url)->toStartWith('https://app.example'.route('register.verify', absolute: false).'?')
            ->and($links[0]->link->expiresAt->getTimestamp())->toBe(now()->addSeconds(EmailedLinks::LIFETIME_SECONDS)->getTimestamp())
            ->and($links[0]->retryUntil()->getTimestamp())->toBe($links[0]->link->expiresAt->getTimestamp());
    });

    it('points a link requested under a foreign Host at app.url', function () {
        $this->post('http://evil.example'.route('register.submit', absolute: false), ['email' => 'new@example.com']);

        expect(linksMailedTo('new@example.com')[0]->link->url)->toStartWith(rtrim((string) config('app.url'), '/').'/')
            ->not->toContain('evil.example');
    });

    it('never shows the address in the link', function () {
        $this->post(route('register.submit'), ['email' => 'new@example.com']);

        expect(urldecode(linksMailedTo('new@example.com')[0]->link->url))->not->toContain('new@example.com')
            ->not->toContain('new%40example.com');
    });

    it('mails the address as Keystone stores it, whatever its case and spacing', function () {
        $this->post(route('register.submit'), ['email' => '  New@EXAMPLE.com ']);

        expect(linksMailedTo('new@example.com'))->toHaveCount(1);
    });

    it('mails nothing to a taken address, records address.claim_attempted on its owner and alerts them', function (Closure $arrange) {
        $owner = $arrange->call($this);

        $response = $this->post(route('register.submit'), ['email' => 'jane@example.com']);

        $response->assertRedirectToRoute('register.link-sent');
        expect(linksMailedTo('jane@example.com'))->toBe([])
            ->and(alertsMailedTo('jane@example.com', SecurityEventType::ADDRESS_CLAIM_ATTEMPTED))->toHaveCount(1);
        $this->assertDatabaseHas('user_security_events', ['user_id' => $owner->getKey(), 'type' => 'address.claim_attempted', 'flow' => 'registration']);
    })->with([
        'held verified' => fn () => $this->createAccount('jane@example.com'),
        'counted as verified, the account holding no verified address' => fn () => $this->createAccount('jane@example.com', verified: false),
        'held by a suspended account' => fn () => tap($this->createAccount('jane@example.com'), fn ($account) => DB::table('users')->where('id', $account->getKey())->update(['suspended_at' => now()])),
    ]);

    it('treats an unverified address of an account holding a verified one as free', function () {
        $account = $this->createAccount('jane@work.example');
        $this->holdAddress($account, 'jane@example.com', verified: false);

        $this->post(route('register.submit'), ['email' => 'jane@example.com']);

        expect(linksMailedTo('jane@example.com'))->toHaveCount(1);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'address.claim_attempted']);
    });

    it('mails a link to the address of a disabled account, alerting no one', function (string $column) {
        $account = $this->createAccount('jane@example.com');
        DB::table('users')->where('id', $account->getKey())->update([$column => now()]);

        $this->post(route('register.submit'), ['email' => 'jane@example.com']);

        expect(linksMailedTo('jane@example.com'))->toHaveCount(1);
        Notification::assertNotSentTo(new AnonymousNotifiable, SecurityAlert::class);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'address.claim_attempted']);
    })->with(['deleted' => 'deleted_at', 'invalidated' => 'invalidated_at']);

    it('alerts every active account the address counts as verified for', function () {
        $this->createAccount('jane@example.com', verified: false);
        $this->createAccount('jane@example.com', verified: false);

        $this->post(route('register.submit'), ['email' => 'jane@example.com']);

        expect(linksMailedTo('jane@example.com'))->toBe([])
            ->and(alertsMailedTo('jane@example.com', SecurityEventType::ADDRESS_CLAIM_ATTEMPTED))->toHaveCount(2);
    });

    it('waits out the timing floor', function (Closure $arrange) {
        $arrange->call($this);

        $response = $this->assertWaitsOutTimingFloor(fn () => $this->post(route('register.submit'), ['email' => 'jane@example.com']));

        $response->assertRedirectToRoute('register.link-sent');
    })->with([
        'a free address' => fn () => null,
        'a taken address' => fn () => $this->createAccount('jane@example.com'),
        'a spent delivery limit' => function () {
            foreach (range(1, config()->integer('keystone.rate_limits.deliveries_per_ten_minutes')) as $ignored) {
                $this->post(route('register.submit'), ['email' => 'jane@example.com']);
            }
        },
    ]);

    it('answers a taken address exactly like a free one', function () {
        $this->createAccount('jane@example.com');

        $this->assertIndistinguishable(
            fn () => $this->post(route('register.submit'), ['email' => 'jane@example.com']),
            fn () => $this->post(route('register.submit'), ['email' => 'new@example.com']),
        );
    });

    it('answers a spent delivery limit exactly like a free address', function () {
        foreach (range(1, config()->integer('keystone.rate_limits.deliveries_per_ten_minutes')) as $ignored) {
            $this->post(route('register.submit'), ['email' => 'jane@example.com']);
        }

        $this->assertIndistinguishable(
            fn () => $this->post(route('register.submit'), ['email' => 'jane@example.com']),
            fn () => $this->post(route('register.submit'), ['email' => 'new@example.com']),
        );
        expect(linksMailedTo('jane@example.com'))->toHaveCount(3);
    });

    it('leaves the session untouched while it mails a link', function () {
        $this->get(route('register'));
        $sessionId = session()->getId();
        $before = session()->all();

        $this->post(route('register.submit'), ['email' => 'new@example.com']);

        expect(session()->getId())->toBe($sessionId)
            ->and(session()->except(['_flash']))->toBe(collect($before)->except(['_flash'])->all());
    });

    it('requires an email address of at most 255 characters, flashing back only the address', function (array $input, string $field) {
        $response = $this->post(route('register.submit'), [...$input, 'extra' => 'typed']);

        $response->assertRedirectToRoute('register')->assertSessionHasErrors([$field]);
        expect(session()->getOldInput())->toBe(array_intersect_key($input, ['email' => true]));
        Notification::assertNothingSent();
    })->with([
        'missing' => [[], 'email'],
        'not an address' => [['email' => 'not-an-address'], 'email'],
        'too long' => [['email' => str_repeat('a', 244).'@example.com'], 'email'],
    ]);

    it('refuses an address that only a lenient reading of the RFC allows', function (string $address) {
        $response = $this->post(route('register.submit'), ['email' => $address]);

        $response->assertRedirectToRoute('register')->assertSessionHasErrors(['email']);
        Notification::assertNothingSent();
    })->with([
        'a quoted local part' => '"jane"@example.com',
        'a domain literal' => 'jane@[127.0.0.1]',
        'a comment' => 'jane(comment)@example.com',
    ]);

    it('mails an internationalized address as Keystone stores it', function (string $typed, string $stored) {
        $this->post(route('register.submit'), ['email' => $typed])->assertRedirectToRoute('register.link-sent');

        expect(linksMailedTo($stored))->toHaveCount(1);
    })->with([
        'a Unicode domain' => ['rene@exämple.com', 'rene@xn--exmple-cua.com'],
        'a Unicode local part' => ['rené@example.com', 'rené@example.com'],
    ]);

    it('sends a signed-in user away without mailing anything', function () {
        $this->signInAccount(new FormTypeSupport);

        $this->post(route('register.submit'), ['email' => 'new@example.com'])->assertRedirect('/');

        expect(linksMailedTo('new@example.com'))->toBe([]);
    });

    it('throttles the submission past the minute\'s allowance', function () {
        foreach (range(1, config()->integer('keystone.rate_limits.requests_per_minute.start')) as $i) {
            $this->post(route('register.submit'), ['email' => "new{$i}@example.com"]);
        }

        $this->post(route('register.submit'), ['email' => 'new@example.com'])->assertTooManyRequests();
    });
});

describe('the delivery limit', function () {
    it('mails an address 3 links in ten minutes, then nothing until the window ends', function () {
        foreach (range(1, 4) as $ignored) {
            $this->post(route('register.submit'), ['email' => 'new@example.com'])->assertRedirectToRoute('register.link-sent');
        }

        expect(linksMailedTo('new@example.com'))->toHaveCount(3);

        $this->travel(RateLimiter::DELIVERY_WINDOW_SECONDS)->seconds();
        $this->post(route('register.submit'), ['email' => 'new@example.com']);

        expect(linksMailedTo('new@example.com'))->toHaveCount(4);
    });

    it('counts a taken address the same way, alerting its owner at most 3 times', function () {
        $this->createAccount('jane@example.com');

        foreach (range(1, 4) as $ignored) {
            $this->post(route('register.submit'), ['email' => 'jane@example.com']);
        }

        expect(alertsMailedTo('jane@example.com', SecurityEventType::ADDRESS_CLAIM_ATTEMPTED))->toHaveCount(3);
    });

    it('counts every spelling of an address as one', function () {
        foreach (['new@example.com', 'NEW@example.com', ' new@EXAMPLE.com', 'New@Example.Com'] as $typed) {
            $this->post(route('register.submit'), ['email' => $typed]);
        }

        expect(linksMailedTo('new@example.com'))->toHaveCount(3);
    });

    it('counts each address apart', function () {
        foreach (range(1, 3) as $ignored) {
            $this->post(route('register.submit'), ['email' => 'jane@example.com']);
        }

        $this->post(route('register.submit'), ['email' => 'new@example.com']);

        expect(linksMailedTo('new@example.com'))->toHaveCount(1);
    });

    it('records limit.tripped once per window, about nobody', function () {
        Event::fake([SecurityEventRecorded::class]);

        foreach (range(1, 5) as $ignored) {
            $this->post(route('register.submit'), ['email' => 'new@example.com']);
        }

        Event::assertDispatchedTimes(SecurityEventRecorded::class, 1);
        Event::assertDispatched(SecurityEventRecorded::class, fn (SecurityEventRecorded $recorded) => $recorded->event->type === SecurityEventType::LIMIT_TRIPPED
            && $recorded->event->reason === 'keystone.delivery_limit'
            && $recorded->event->flow === 'registration'
            && $recorded->event->user_id === null);
    });

    it('mails nothing while the limiter store is down, reporting the failure and showing the usual step', function () {
        Exceptions::fake();
        $this->mock(CacheRateLimiter::class)->shouldReceive('increment')->andThrow(new RuntimeException('Store down.'));

        $response = $this->post(route('register.submit'), ['email' => 'new@example.com']);

        $response->assertRedirectToRoute('register.link-sent');
        Notification::assertNothingSent();
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Store down.');
    });
});

describe('the email submission without email verification', function () {
    beforeEach(function () {
        config(['keystone.email_verification.required' => false]);
    });

    it('starts registering a free address unverified and sends the user on to finish, mailing nothing', function () {
        $response = $this->post(route('register.submit'), ['email' => ' New@EXAMPLE.com ']);

        $response->assertRedirectToRoute('register.finish');
        expect(Keystone::guard()->registration())->address->toBe('new@example.com')->verified->toBeFalse();
        Notification::assertNothingSent();
    });

    it('starts the registration on a new session id', function () {
        $this->get(route('register'));
        $before = session()->getId();

        $this->post(route('register.submit'), ['email' => 'new@example.com']);

        expect(session()->getId())->not->toBe($before);
    });

    it('starts registering a taken address, records address.claim_attempted on its owner and alerts them, mailing the typed address no link', function (Closure $arrange) {
        $owner = $arrange->call($this);

        $response = $this->post(route('register.submit'), ['email' => 'jane@example.com']);

        $response->assertRedirectToRoute('register.finish');
        expect(Keystone::guard()->registration())->address->toBe('jane@example.com')->verified->toBeFalse()
            ->and(linksMailedTo('jane@example.com'))->toBe([])
            ->and(alertsMailedTo('jane@example.com', SecurityEventType::ADDRESS_CLAIM_ATTEMPTED))->toHaveCount(1);
        Notification::assertNotSentTo(new AnonymousNotifiable, EmailedLinkMail::class);
        $this->assertDatabaseHas('user_security_events', ['user_id' => $owner->getKey(), 'type' => 'address.claim_attempted', 'flow' => 'registration']);
    })->with([
        'held verified' => fn () => $this->createAccount('jane@example.com'),
        'counted as verified, the account holding no verified address' => fn () => $this->createAccount('jane@example.com', verified: false),
        'held by a suspended account' => fn () => tap($this->createAccount('jane@example.com'), fn ($account) => DB::table('users')->where('id', $account->getKey())->update(['suspended_at' => now()])),
    ]);

    it('alerts every active account the address counts as verified for', function () {
        $this->createAccount('jane@example.com', verified: false);
        $this->createAccount('jane@example.com', verified: false);

        $this->post(route('register.submit'), ['email' => 'jane@example.com']);

        expect(alertsMailedTo('jane@example.com', SecurityEventType::ADDRESS_CLAIM_ATTEMPTED))->toHaveCount(2);
    });

    it('alerts no one for an address a disabled account holds, starting the registration all the same', function (string $column) {
        $account = $this->createAccount('jane@example.com');
        DB::table('users')->where('id', $account->getKey())->update([$column => now()]);

        $response = $this->post(route('register.submit'), ['email' => 'jane@example.com']);

        $response->assertRedirectToRoute('register.finish');
        expect(Keystone::guard()->registration())->address->toBe('jane@example.com');
        Notification::assertNothingSent();
        $this->assertDatabaseMissing('user_security_events', ['type' => 'address.claim_attempted']);
    })->with(['deleted' => 'deleted_at', 'invalidated' => 'invalidated_at']);

    it('starts the registration once the address spent its deliveries, alerting its owner no more', function () {
        $this->createAccount('jane@example.com');

        foreach (range(1, config()->integer('keystone.rate_limits.deliveries_per_ten_minutes')) as $ignored) {
            $this->post(route('register.submit'), ['email' => 'jane@example.com']);
        }

        Keystone::guard()->endRegistration();
        $response = $this->post(route('register.submit'), ['email' => 'jane@example.com']);

        $response->assertRedirectToRoute('register.finish');
        expect(Keystone::guard()->registration())->address->toBe('jane@example.com')
            ->and(alertsMailedTo('jane@example.com', SecurityEventType::ADDRESS_CLAIM_ATTEMPTED))->toHaveCount(3);
    });

    it('answers a taken address and a spent delivery limit exactly like a free one', function () {
        $this->createAccount('jane@example.com');
        foreach (range(1, config()->integer('keystone.rate_limits.deliveries_per_ten_minutes')) as $ignored) {
            $this->post(route('register.submit'), ['email' => 'spent@example.com']);
        }

        $this->assertIndistinguishable(
            fn () => $this->post(route('register.submit'), ['email' => 'jane@example.com']),
            fn () => $this->post(route('register.submit'), ['email' => 'new@example.com']),
        );
        $this->assertIndistinguishable(
            fn () => $this->post(route('register.submit'), ['email' => 'spent@example.com']),
            fn () => $this->post(route('register.submit'), ['email' => 'other@example.com']),
        );
    });

    it('waits out the timing floor', function (Closure $arrange) {
        $arrange->call($this);

        $response = $this->assertWaitsOutTimingFloor(fn () => $this->post(route('register.submit'), ['email' => 'jane@example.com']));

        $response->assertRedirectToRoute('register.finish');
    })->with([
        'a free address' => fn () => null,
        'a taken address' => fn () => $this->createAccount('jane@example.com'),
        'a spent delivery limit' => function () {
            foreach (range(1, config()->integer('keystone.rate_limits.deliveries_per_ten_minutes')) as $ignored) {
                $this->post(route('register.submit'), ['email' => 'jane@example.com']);
            }
        },
    ]);

    it('still refuses an invalid address without starting a registration', function () {
        $response = $this->post(route('register.submit'), ['email' => 'not-an-address']);

        $response->assertRedirectToRoute('register')->assertSessionHasErrors(['email']);
        expect(Keystone::guard()->registration())->toBeNull();
    });

    it('sends a signed-in user away without starting a registration', function () {
        $this->signInAccount(new FormTypeSupport);

        $this->post(route('register.submit'), ['email' => 'new@example.com'])->assertRedirect('/');

        expect(Keystone::guard()->registration())->toBeNull();
    });
});
