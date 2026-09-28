<?php

use Carbon\CarbonImmutable;
use ClaudioDekker\Keystone\Methods\StoredCredential;
use ClaudioDekker\Keystone\RequestContext;
use ClaudioDekker\Keystone\SecurityEvent;
use ClaudioDekker\Keystone\SecurityEventRecorded;
use ClaudioDekker\Keystone\SecurityEventRecorder;
use ClaudioDekker\Keystone\SecurityEventType;
use ClaudioDekker\Keystone\Tests\Fixtures\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;

beforeEach(function () {
    config([
        'logging.channels.keystone-test' => ['driver' => 'monolog', 'handler' => TestHandler::class],
        'keystone.log_channel' => 'keystone-test',
    ]);
});

function logRecords(string $channel = 'keystone-test'): array
{
    return Log::channel($channel)->getLogger()->getHandlers()[0]->getRecords();
}

function loggedContext(): array
{
    return array_map(fn (LogRecord $record) => $record->context, logRecords());
}

function captureContext(string $ip = '203.0.113.7', string $path = 'login', string $userAgent = 'Mozilla/5.0 Firefox'): void
{
    app()->instance(RequestContext::class, new RequestContext(ipAddress: $ip, userAgent: $userAgent, path: $path, requestId: '01JREQUEST'));
}

function recorder(): SecurityEventRecorder
{
    return new SecurityEventRecorder;
}

describe('the log line', function () {
    it('carries every fixed field as its context', function () {
        $this->freezeSecond();
        captureContext();
        $user = User::factory()->create();

        recorder()->record(
            SecurityEventType::PROOF_REJECTED,
            account: $user,
            flow: 'sign-in',
            credentialType: 'form',
            credential: new StoredCredential(7, null, null, 'Laptop'),
            reason: 'form.mismatch',
        );

        expect(logRecords())->toHaveCount(1)
            ->and(logRecords()[0]->message)->toBe('keystone.security_event')
            ->and(loggedContext()[0])->toBe([
                'occurred_at' => now()->utc()->toIso8601ZuluString(),
                'type' => 'proof.rejected',
                'user_id' => $user->getKey(),
                'actor' => 'user',
                'flow' => 'sign-in',
                'credential_type' => 'form',
                'credential_id' => 7,
                'credential_label' => 'Laptop',
                'reason' => 'form.mismatch',
                'ip_address' => '203.0.113.7',
                'location' => null,
                'user_agent' => 'Mozilla/5.0 Firefox',
                'known_device' => null,
                'request_id' => '01JREQUEST',
            ]);
    });

    it('goes to the app\'s default channel when no channel is configured', function () {
        config(['keystone.log_channel' => null, 'logging.default' => 'keystone-test']);

        recorder()->record(SecurityEventType::SIGNED_OUT, account: User::factory()->create());

        expect(logRecords())->toHaveCount(1);
    });

    it('keeps typed input from forging a second line', function () {
        captureContext(userAgent: "Firefox\n[2026-09-28 12:00:00] testing.INFO: keystone.security_event {\"type\":\"signed_in\"}");

        recorder()->record(SecurityEventType::SIGNED_OUT, account: User::factory()->create());

        expect(substr_count(logRecords()[0]->formatted, "\n"))->toBe(1);
    });

    it('carries no request context outside a request', function () {
        recorder()->record(SecurityEventType::SIGNED_OUT, account: User::factory()->create());

        expect(loggedContext()[0])->toMatchArray(['ip_address' => null, 'user_agent' => null, 'request_id' => null]);
    });

    it('truncates the user agent and the credential label', function () {
        captureContext(userAgent: str_repeat('a', 600));

        recorder()->record(
            SecurityEventType::SIGNED_IN,
            account: User::factory()->create(),
            credentialType: 'form',
            credential: new StoredCredential(7, null, null, str_repeat('b', 70)),
        );

        expect(loggedContext()[0])->toMatchArray(['user_agent' => str_repeat('a', 512), 'credential_label' => str_repeat('b', 64)]);
    });

    it('keeps only short reason codes owned by the event\'s credential type', function (?string $type, string $reason, string $kept) {
        recorder()->record(SecurityEventType::PROOF_REJECTED, account: User::factory()->create(), credentialType: $type, reason: $reason);

        expect(loggedContext()[0]['reason'])->toBe($kept);
    })->with([
        'the type\'s own' => ['form', 'form.mismatch', 'form.mismatch'],
        'core\'s' => ['form', 'keystone.barred', 'keystone.barred'],
        'another type\'s' => ['form', 'rogue.mismatch', 'form.invalid_reason'],
        'unprefixed' => ['form', 'mismatch', 'form.invalid_reason'],
        'a bare prefix' => ['form', 'form.', 'form.invalid_reason'],
        'typed input' => ['form', 'form.Jane Doe', 'form.invalid_reason'],
        '64 characters' => ['form', 'form.'.str_repeat('a', 59), 'form.'.str_repeat('a', 59)],
        '65 characters' => ['form', 'form.'.str_repeat('a', 60), 'form.invalid_reason'],
        'without a type' => [null, 'remembered', 'remembered'],
        'typed input without a type' => [null, 'Jane Doe', 'keystone.invalid_reason'],
    ]);
});

describe('the audit trail', function () {
    it('holds an event about an account', function () {
        $this->freezeSecond();
        captureContext();
        $user = User::factory()->create();

        recorder()->record(
            SecurityEventType::SIGNED_IN,
            account: $user,
            flow: 'sign-in',
            credentialType: 'form',
            credential: new StoredCredential(7, null, null, 'Laptop'),
        );

        $event = SecurityEvent::sole();
        expect($event->type)->toBe(SecurityEventType::SIGNED_IN)
            ->and($event->user_id)->toEqual($user->getKey())
            ->and($event->occurred_at->equalTo(now()))->toBeTrue()
            ->and($event->only(['flow', 'credential_type', 'credential_id', 'credential_label', 'reason', 'ip_address', 'user_agent', 'request_id']))->toBe([
                'flow' => 'sign-in',
                'credential_type' => 'form',
                'credential_id' => 7,
                'credential_label' => 'Laptop',
                'reason' => null,
                'ip_address' => '203.0.113.7',
                'user_agent' => 'Mozilla/5.0 Firefox',
                'request_id' => '01JREQUEST',
            ]);
    });

    it('stores the time in UTC whatever the app\'s timezone', function () {
        $timezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Amsterdam');
        $this->travelTo(CarbonImmutable::parse('2026-09-28 12:00:00', 'Europe/Amsterdam'));

        try {
            recorder()->record(SecurityEventType::SIGNED_OUT, account: User::factory()->create());
        } finally {
            date_default_timezone_set($timezone);
        }

        expect(DB::table('user_security_events')->value('occurred_at'))->toBe('2026-09-28 10:00:00');
    });

    it('keeps an event about nobody out of the trail', function () {
        recorder()->record(SecurityEventType::PROOF_REJECTED);

        $this->assertDatabaseCount('user_security_events', 0);
        expect(logRecords())->toHaveCount(1);
    });

    it('can\'t be stopped by a model listener', function () {
        SecurityEvent::creating(fn () => false);

        recorder()->record(SecurityEventType::SIGNED_OUT, account: User::factory()->create());

        $this->assertDatabaseCount('user_security_events', 1);
    });
});

describe('anonymous events', function () {
    it('logs an identical anonymous event once per minute', function () {
        $this->freezeSecond();
        captureContext();

        recorder()->record(SecurityEventType::PROOF_REJECTED);
        $this->travel(59)->seconds();
        recorder()->record(SecurityEventType::PROOF_REJECTED);
        $this->travel(1)->seconds();
        recorder()->record(SecurityEventType::PROOF_REJECTED);

        expect(logRecords())->toHaveCount(2);
    });

    it('logs anonymous events differing in type, IP address or path', function () {
        captureContext();
        recorder()->record(SecurityEventType::PROOF_REJECTED);

        recorder()->record(SecurityEventType::SIGNED_OUT);
        captureContext(ip: '198.51.100.1');
        recorder()->record(SecurityEventType::PROOF_REJECTED);
        captureContext(path: 'sudo');
        recorder()->record(SecurityEventType::PROOF_REJECTED);

        expect(logRecords())->toHaveCount(4);
    });

    it('logs every anonymous event while the cache is down', function () {
        Cache::shouldReceive('add')->andThrow(new RuntimeException('Cache down.'));

        recorder()->record(SecurityEventType::PROOF_REJECTED);
        recorder()->record(SecurityEventType::PROOF_REJECTED);

        expect(logRecords())->toHaveCount(2);
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Cache down.');
    });

    it('logs every identical event about an account', function () {
        $user = User::factory()->create();

        recorder()->record(SecurityEventType::PROOF_REJECTED, account: $user);
        recorder()->record(SecurityEventType::PROOF_REJECTED, account: $user);

        expect(logRecords())->toHaveCount(2);
    });
});

describe('the dispatched event', function () {
    it('carries the whole stored entry', function () {
        Event::fake([SecurityEventRecorded::class]);
        captureContext();
        $user = User::factory()->create();

        recorder()->record(SecurityEventType::SIGNED_IN, account: $user);

        Event::assertDispatched(fn (SecurityEventRecorded $recorded) => $recorded->event->is(SecurityEvent::sole())
            && $recorded->event->ip_address === '203.0.113.7');
    });

    it('carries an event about nobody unstored', function () {
        Event::fake([SecurityEventRecorded::class]);

        recorder()->record(SecurityEventType::PROOF_REJECTED);

        Event::assertDispatched(fn (SecurityEventRecorded $recorded) => ! $recorded->event->exists
            && $recorded->event->type === SecurityEventType::PROOF_REJECTED);
    });
});

describe('a failing step', function () {
    it('still stores and dispatches when the log line fails', function () {
        Event::fake([SecurityEventRecorded::class]);
        Log::shouldReceive('channel')->andThrow(new RuntimeException('Log down.'));

        recorder()->record(SecurityEventType::SIGNED_OUT, account: User::factory()->create());

        $this->assertDatabaseCount('user_security_events', 1);
        Event::assertDispatched(SecurityEventRecorded::class);
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Log down.');
    });

    it('still logs and dispatches when the trail can\'t be written', function () {
        Event::fake([SecurityEventRecorded::class]);
        $user = User::factory()->create()->setConnection('missing');

        recorder()->record(SecurityEventType::SIGNED_OUT, account: $user);

        expect(logRecords())->toHaveCount(1);
        Event::assertDispatched(SecurityEventRecorded::class);
        Exceptions::assertReported(InvalidArgumentException::class);
    });

    it('still logs and stores when a listener fails', function () {
        Event::listen(SecurityEventRecorded::class, fn () => throw new RuntimeException('Listener broke.'));

        recorder()->record(SecurityEventType::SIGNED_OUT, account: User::factory()->create());

        expect(logRecords())->toHaveCount(1);
        $this->assertDatabaseCount('user_security_events', 1);
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Listener broke.');
    });
});
