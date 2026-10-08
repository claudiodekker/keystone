<?php

use ClaudioDekker\Keystone\Exceptions\Throttled;
use ClaudioDekker\Keystone\Flow;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\PendingStage;
use ClaudioDekker\Keystone\RateLimiter;
use ClaudioDekker\Keystone\RequestContext;
use ClaudioDekker\Keystone\SecurityEventRecorded;
use ClaudioDekker\Keystone\StepKind;
use ClaudioDekker\Keystone\Tests\Fixtures\FormType;
use ClaudioDekker\Keystone\Tests\Fixtures\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;

beforeEach(function () {
    $this->freezeSecond();
});

function limiter(string $ip = '203.0.113.5'): RateLimiter
{
    $request = Request::create('/', server: ['REMOTE_ADDR' => $ip]);

    return new RateLimiter($request, RequestContext::capture($request), Keystone::guard());
}

function hitTimes(RateLimiter $limiter, StepKind $kind, int $times): void
{
    foreach (range(1, $times) as $ignored) {
        $limiter->hitRequest($kind);
    }
}

function failTimes(RateLimiter $limiter, int $times, string $identifier = 'nobody@example.com', ?User $account = null, string $type = 'form', Flow $flow = Flow::SIGN_IN, bool $shares = false): void
{
    foreach (range(1, $times) as $ignored) {
        $limiter->takeFailedAttempt($flow, new FormType($type, sharesFailedAttempts: $shares), $account, $identifier);
    }
}

function failSharedTimes(RateLimiter $limiter, int $times, Flow $flow = Flow::CHALLENGE): void
{
    failTimes($limiter, $times, flow: $flow, shares: true);
}

function retryAfter(Closure $callback): ?int
{
    try {
        $callback();
    } catch (Throttled $throttled) {
        return $throttled->retryAfterSeconds;
    }

    return null;
}

describe('request limit', function () {
    it('allows each step kind its requests a minute, then refuses until the minute ends', function (StepKind $kind, int $allowance) {
        hitTimes(limiter(), $kind, $allowance);

        expect(retryAfter(fn () => limiter()->hitRequest($kind)))->toBe(60);
    })->with([
        'view' => [StepKind::VIEW, 60],
        'start' => [StepKind::START, 10],
        'submit' => [StepKind::SUBMIT, 10],
        'change' => [StepKind::CHANGE, 10],
    ]);

    it('tells how long until the spent key expires', function () {
        hitTimes(limiter(), StepKind::SUBMIT, 10);

        $this->travel(15)->seconds();

        expect(retryAfter(fn () => limiter()->hitRequest(StepKind::SUBMIT)))->toBe(45);
    });

    it('allows requests again once the minute has passed', function () {
        hitTimes(limiter(), StepKind::SUBMIT, 10);

        $this->travel(60)->seconds();

        expect(retryAfter(fn () => limiter()->hitRequest(StepKind::SUBMIT)))->toBeNull();
    });

    it('counts each step kind separately', function () {
        hitTimes(limiter(), StepKind::SUBMIT, 10);

        expect(retryAfter(fn () => limiter()->hitRequest(StepKind::CHANGE)))->toBeNull();
    });

    it('counts each address separately', function () {
        hitTimes(limiter('203.0.113.5'), StepKind::SUBMIT, 10);

        expect(retryAfter(fn () => limiter('203.0.113.6')->hitRequest(StepKind::SUBMIT)))->toBeNull()
            ->and(retryAfter(fn () => limiter('2001:db8:0:2::1')->hitRequest(StepKind::SUBMIT)))->toBeNull();
    });

    it('shares one key between addresses of one network', function (string $spent, string $other) {
        hitTimes(limiter($spent), StepKind::SUBMIT, 10);

        expect(retryAfter(fn () => limiter($other)->hitRequest(StepKind::SUBMIT)))->toBe(60);
    })->with([
        'one IPv6 /64' => ['2001:db8:0:1::1', '2001:db8:0:1:ffff:ffff:ffff:ffff'],
        'IPv4-mapped IPv6 and IPv4' => ['::ffff:203.0.113.5', '203.0.113.5'],
        'unusable addresses' => ['', 'not-an-address'],
    ]);

    it('limits the account the session names across addresses', function () {
        $user = User::factory()->create();
        Keystone::guard()->setUser($user);
        hitTimes(limiter('203.0.113.5'), StepKind::SUBMIT, 10);

        expect(retryAfter(fn () => limiter('198.51.100.7')->hitRequest(StepKind::SUBMIT)))->toBe(60);
    });

    it('limits the account a held sign-in names across addresses', function () {
        $user = User::factory()->create();
        Keystone::guard()->hold($user, 'form', PendingStage::CHALLENGE, '/');
        hitTimes(limiter('203.0.113.5'), StepKind::SUBMIT, 10);

        expect(retryAfter(fn () => limiter('198.51.100.7')->hitRequest(StepKind::SUBMIT)))->toBe(60);
    });

    it('lets a request under its limit through without reporting anything', function () {
        expect(retryAfter(fn () => limiter()->hitRequest(StepKind::SUBMIT)))->toBeNull();

        Exceptions::assertNothingReported();
    });

    it('lets requests through while the store is down, reporting the failure', function () {
        $this->mock(CacheRateLimiter::class)->shouldReceive('increment')->andThrow(new RuntimeException('Store down.'));

        expect(retryAfter(fn () => limiter()->hitRequest(StepKind::SUBMIT)))->toBeNull();

        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Store down.');
    });
});

describe('failed-attempt limit', function () {
    it('allows 20 failed attempts an hour, then refuses until the hour ends', function () {
        failTimes(limiter(), 20);

        $this->travel(10)->minutes();

        expect(retryAfter(fn () => failTimes(limiter(), 1)))->toBe(3000);
    });

    it('allows failed attempts again once the hour has passed', function () {
        failTimes(limiter(), 20);

        $this->travel(1)->hour();

        expect(retryAfter(fn () => failTimes(limiter(), 1)))->toBeNull();
    });

    it('keeps refused attempts counted', function () {
        $taken = limiter()->takeFailedAttempt(Flow::SIGN_IN, new FormType, null, 'nobody@example.com');
        failTimes(limiter(), 19);
        retryAfter(fn () => failTimes(limiter(), 1));

        limiter()->giveBack($taken);

        expect(retryAfter(fn () => failTimes(limiter(), 1)))->toBe(3600);
    });

    it('gives back an attempt that did not count', function () {
        failTimes(limiter(), 19);
        $taken = limiter()->takeFailedAttempt(Flow::SIGN_IN, new FormType, null, 'nobody@example.com');

        limiter()->giveBack($taken);

        expect(retryAfter(fn () => failTimes(limiter(), 1)))->toBeNull()
            ->and(retryAfter(fn () => failTimes(limiter(), 1)))->toBe(3600);
    });

    it('gives back an attempt later in the window it was taken in', function () {
        failTimes(limiter(), 19);
        $taken = limiter()->takeFailedAttempt(Flow::SIGN_IN, new FormType, null, 'nobody@example.com');
        $this->travel(30)->minutes();

        limiter()->giveBack($taken);

        expect(retryAfter(fn () => failTimes(limiter(), 1)))->toBeNull();
    });

    it('gives nothing back once the attempt\'s hour has ended', function () {
        $taken = limiter()->takeFailedAttempt(Flow::SIGN_IN, new FormType, null, 'nobody@example.com');
        $this->travel(1)->hour();
        failTimes(limiter(), 20);

        limiter()->giveBack($taken);

        expect(retryAfter(fn () => failTimes(limiter(), 1)))->toBe(3600);
    });

    it('counts from any address', function () {
        failTimes(limiter('203.0.113.5'), 20);

        expect(retryAfter(fn () => failTimes(limiter('198.51.100.7'), 1)))->toBe(3600);
    });

    it('counts each credential type separately', function () {
        failTimes(limiter(), 20, type: 'form');

        expect(retryAfter(fn () => failTimes(limiter(), 1, type: 'other-form')))->toBeNull();
    });

    it('keys a known account by its id, whatever was typed', function () {
        $user = User::factory()->create();
        failTimes(limiter(), 20, 'jane@example.com', $user);

        expect(retryAfter(fn () => failTimes(limiter(), 1, 'Jane.Doe', $user)))->toBe(3600)
            ->and(retryAfter(fn () => failTimes(limiter(), 1, 'jane@example.com')))->toBeNull()
            ->and(retryAfter(fn () => failTimes(limiter(), 1, 'jane@example.com', User::factory()->create())))->toBeNull();
    });

    it('shares one key between spellings of an unmatched identifier', function (string $spent, string $other) {
        failTimes(limiter(), 20, $spent);

        expect(retryAfter(fn () => failTimes(limiter(), 1, $other)))->toBe(3600);
    })->with([
        'composition' => ["rene\u{301}@example.com", 'rené@example.com'],
        'case' => ['Nobody@Example.com', 'nobody@example.com'],
    ]);

    it('counts each flow separately for a type that shares no count', function () {
        failTimes(limiter(), 20, flow: Flow::CHALLENGE);

        expect(retryAfter(fn () => failTimes(limiter(), 1)))->toBeNull();
    });

    it('keeps a sharing type\'s challenge count apart from its sign-in count', function () {
        failSharedTimes(limiter(), 20);

        expect(retryAfter(fn () => failSharedTimes(limiter(), 1, Flow::SIGN_IN)))->toBeNull()
            ->and(retryAfter(fn () => failSharedTimes(limiter(), 1)))->toBe(3600);
    });

    it('allows a sharing type 100 failed attempts a day, then refuses until the day ends', function () {
        foreach (range(1, 5) as $ignored) {
            failSharedTimes(limiter(), 20);
            $this->travel(1)->hour();
        }

        expect(retryAfter(fn () => failSharedTimes(limiter(), 1)))->toBe(86400 - 5 * 3600);
    });

    it('allows a sharing type failed attempts again once the day has passed', function () {
        foreach (range(1, 5) as $ignored) {
            failSharedTimes(limiter(), 20);
            $this->travel(1)->hour();
        }

        $this->travel(19)->hours();

        expect(retryAfter(fn () => failSharedTimes(limiter(), 1)))->toBeNull();
    });

    it('leaves the day\'s ceiling alone while the hour is spent', function () {
        failSharedTimes(limiter(), 20);
        foreach (range(1, 100) as $ignored) {
            retryAfter(fn () => failSharedTimes(limiter(), 1));
        }

        $this->travel(1)->hour();

        expect(retryAfter(fn () => failSharedTimes(limiter(), 1)))->toBeNull();
    });

    it('records each trip once, however often the spent hour refuses after the ceiling is spent', function () {
        Event::fake([SecurityEventRecorded::class]);
        foreach (range(1, 5) as $ignored) {
            failSharedTimes(limiter(), 20);
            $this->travel(1)->hour();
        }

        foreach (range(1, 30) as $ignored) {
            retryAfter(fn () => failSharedTimes(limiter(), 1));
        }

        Event::assertDispatchedTimes(SecurityEventRecorded::class, 2);
    });

    it('gives back a sharing type\'s attempt to the day\'s ceiling too', function () {
        config(['keystone.rate_limits.failed_attempts_per_hour' => 200]);
        failSharedTimes(limiter(), 99);
        $taken = limiter()->takeFailedAttempt(Flow::CHALLENGE, new FormType(sharesFailedAttempts: true), null, 'nobody@example.com');

        limiter()->giveBack($taken);

        expect(retryAfter(fn () => failSharedTimes(limiter(), 1)))->toBeNull()
            ->and(retryAfter(fn () => failSharedTimes(limiter(), 1)))->toBe(86400);
    });

    it('takes a failed attempt under its limit without reporting anything', function (bool $shares) {
        expect(retryAfter(fn () => failTimes(limiter(), 1, flow: Flow::CHALLENGE, shares: $shares)))->toBeNull();

        Exceptions::assertNothingReported();
    })->with(['a type that shares no count' => false, 'a sharing type' => true]);

    it('refuses while the store is down, reporting the failure', function () {
        $this->mock(CacheRateLimiter::class)->shouldReceive('increment')->andThrow(new RuntimeException('Store down.'));

        expect(retryAfter(fn () => failTimes(limiter(), 1)))->toBe(RateLimiter::OUTAGE_RETRY_AFTER_SECONDS);

        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Store down.');
    });
});

it('keeps no identifier or address in clear in the store', function () {
    $user = User::factory()->create();
    Keystone::guard()->setUser($user);
    limiter('203.0.113.5')->hitRequest(StepKind::SUBMIT);
    failTimes(limiter('203.0.113.5'), 1, 'nobody@example.com');
    failTimes(limiter('203.0.113.5'), 1, 'jane@example.com', $user);

    $stored = (fn () => $this->storage)->call(Cache::store('array')->getStore());

    expect($stored)->not->toBeEmpty()
        ->and(json_encode($stored, JSON_THROW_ON_ERROR))->not->toContain('nobody')
        ->not->toContain('203.0.113');
});

describe('limit.tripped', function () {
    it('records the first refused request in a window, about the signed-in account', function () {
        $user = User::factory()->create();
        Keystone::guard()->setUser($user);
        hitTimes(limiter('203.0.113.5'), StepKind::SUBMIT, 10);

        retryAfter(fn () => limiter('198.51.100.7')->hitRequest(StepKind::SUBMIT));
        retryAfter(fn () => limiter('198.51.100.7')->hitRequest(StepKind::SUBMIT));

        $this->assertDatabaseCount('user_security_events', 1);
        $this->assertDatabaseHas('user_security_events', [
            'type' => 'limit.tripped',
            'user_id' => $user->getKey(),
            'flow' => null,
            'reason' => 'keystone.request_limit',
        ]);
    });

    it('records a guest\'s request-limit trip about nobody', function () {
        Event::fake([SecurityEventRecorded::class]);
        hitTimes(limiter(), StepKind::SUBMIT, 10);

        retryAfter(fn () => limiter()->hitRequest(StepKind::SUBMIT));
        retryAfter(fn () => limiter()->hitRequest(StepKind::SUBMIT));

        Event::assertDispatchedTimes(SecurityEventRecorded::class, 1);
        $this->assertDatabaseCount('user_security_events', 0);
    });

    it('records the first refused failed attempt in a window, about the account', function () {
        $user = User::factory()->create();
        failTimes(limiter(), 20, 'jane@example.com', $user);

        retryAfter(fn () => failTimes(limiter(), 1, 'jane@example.com', $user));
        retryAfter(fn () => failTimes(limiter(), 1, 'jane@example.com', $user));

        $this->assertDatabaseCount('user_security_events', 1);
        $this->assertDatabaseHas('user_security_events', [
            'type' => 'limit.tripped',
            'user_id' => $user->getKey(),
            'flow' => 'sign-in',
            'credential_type' => 'form',
            'reason' => 'keystone.failed_attempt_limit',
        ]);
    });

    it('records a trip for an unmatched identifier about nobody', function () {
        Event::fake([SecurityEventRecorded::class]);
        failTimes(limiter(), 20);

        retryAfter(fn () => failTimes(limiter(), 1));
        retryAfter(fn () => failTimes(limiter(), 1));

        Event::assertDispatchedTimes(SecurityEventRecorded::class, 1);
        $this->assertDatabaseCount('user_security_events', 0);
    });

    it('dispatches Laravel\'s Lockout event with each trip', function () {
        Event::fake([Lockout::class]);
        $user = User::factory()->create();
        hitTimes(limiter(), StepKind::SUBMIT, 10);
        failTimes(limiter(), 20, 'jane@example.com', $user);

        retryAfter(fn () => limiter()->hitRequest(StepKind::SUBMIT));
        retryAfter(fn () => limiter()->hitRequest(StepKind::SUBMIT));
        retryAfter(fn () => failTimes(limiter(), 1, 'jane@example.com', $user));
        retryAfter(fn () => failTimes(limiter(), 1, 'jane@example.com', $user));

        Event::assertDispatchedTimes(Lockout::class, 2);
        Event::assertDispatched(Lockout::class, fn (Lockout $event) => $event->request->ip() === '203.0.113.5');
    });

    it('still refuses when a Lockout listener fails, reporting the failure', function () {
        Event::listen(Lockout::class, fn () => throw new RuntimeException('Listener broke.'));
        failTimes(limiter(), 20);

        expect(retryAfter(fn () => failTimes(limiter(), 1)))->toBe(3600);

        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Listener broke.');
    });

    it('records nothing while the store is down', function () {
        Event::fake([SecurityEventRecorded::class, Lockout::class]);
        $this->mock(CacheRateLimiter::class)->shouldReceive('increment')->andThrow(new RuntimeException('Store down.'));

        retryAfter(fn () => failTimes(limiter(), 1));

        Event::assertNotDispatched(SecurityEventRecorded::class);
        Event::assertNotDispatched(Lockout::class);
    });
});
