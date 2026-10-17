<?php

use ClaudioDekker\Keystone\Events\SecurityEventRecorded;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Models\UserSecurityEvent;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Sleep;

afterEach(function () {
    Model::encryptUsing(null);
    TrustProxies::flushState();
});

it('records signed_in for the account when a user signs in', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignedIn($response, '/');

    $events = $this->securityEvents()->for($user);

    expect($events)->toHaveCount(1)
        ->and($events[0]->type)->toBe(SecurityEventType::SIGNED_IN)
        ->and($events[0]->user_id)->toBe($user->getAuthIdentifier());
});

it('records proof.rejected for the account when its sign-in is refused', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    $this->assertSignInRefused($response, __('keystone::messages.credentials-rejected'));

    $events = $this->securityEvents()->for($user);

    expect($events)->toHaveCount(1)
        ->and($events[0]->type)->toBe(SecurityEventType::PROOF_REJECTED)
        ->and($events[0]->credential_type)->toBe($support->type()->name())
        ->and($events[0]->credential_id)->toBeNull();
});

it('writes only a log line for a refused sign-in with a made-up address, and stores the typed address nowhere', function () {
    Event::fake([SecurityEventRecorded::class]);
    $support = $this->supportFor(Surface::SIGN_IN);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'grace@example.com',
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    $this->assertSignInRefused($response, __('keystone::messages.credentials-rejected'));

    $lines = $this->securityEvents()->logged();

    expect($this->securityEvents()->stored())->toBeEmpty()
        ->and($lines)->toHaveCount(1)
        ->and($lines[0]['type'])->toBe('proof.rejected')
        ->and($lines[0]['user_id'])->toBeNull()
        ->and($lines[0]['credential_type'])->toBe($support->type()->name())
        ->and(json_encode($lines, JSON_THROW_ON_ERROR))->not->toContain('grace');

    Event::assertNotDispatched(SecurityEventRecorded::class);
});

it('logs identical refusals for made-up addresses once per 60 seconds, per type, address and path', function () {
    $support = $this->supportFor(Surface::SIGN_IN);
    $refuse = fn (string $ipAddress) => $this->withServerVariables(['REMOTE_ADDR' => $ipAddress])->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'grace@example.com',
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    $refuse('203.0.113.9');
    $refuse('203.0.113.9');
    $refuse('203.0.113.10');
    $this->travel(59)->seconds();
    $refuse('203.0.113.9');
    $this->travel(1)->seconds();
    $refuse('203.0.113.9');

    expect(array_column($this->securityEvents()->logged(), 'ip_address'))->toBe(['203.0.113.9', '203.0.113.10', '203.0.113.9']);
});

it('logs every refusal for a made-up address while the cache fails', function () {
    config(['cache.default' => 'broken', 'cache.stores.broken' => ['driver' => 'nonexistent']]);
    $support = $this->supportFor(Surface::SIGN_IN);
    $refuse = fn () => $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'grace@example.com',
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    $refuse();
    $refuse();

    expect($this->securityEvents()->logged())->toHaveCount(2);
    Exceptions::assertReported(InvalidArgumentException::class);
});

it('dispatches SecurityEventRecorded with the whole entry', function () {
    Event::fake([SecurityEventRecorded::class]);
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $event = $this->securityEvents()->for($user)->sole();

    Event::assertDispatched(SecurityEventRecorded::class, fn (SecurityEventRecorded $recorded) => $recorded->entry->is($event) && $recorded->entry->type === SecurityEventType::SIGNED_IN);
});

it("carries the request's address, user agent and request id, in the row and in the log line", function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->withHeader('User-Agent', 'Mozilla/5.0 (X11; Linux x86_64)')->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $event = $this->securityEvents()->for($user)->sole();
    $line = $this->securityEvents()->logged()[0];

    expect($event->ip_address)->toBe('203.0.113.9')
        ->and($event->user_agent)->toBe('Mozilla/5.0 (X11; Linux x86_64)')
        ->and($event->request_id)->toMatch('/^[0-9a-hjkmnp-tv-z]{26}$/')
        ->and($line['ip_address'])->toBe('203.0.113.9')
        ->and($line['user_agent'])->toBe('Mozilla/5.0 (X11; Linux x86_64)')
        ->and($line['request_id'])->toBe($event->request_id);
});

it('gives each request its own request id', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    $ids = $this->securityEvents()->for($user)->pluck('request_id');

    expect($ids)->toHaveCount(2)
        ->and($ids[0])->not->toBe($ids[1]);
});

it('replaces control characters and invalid UTF-8 in the user agent and cuts it to 512 characters', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $this->withHeader('User-Agent', "Mozilla\x00\n/5.0\xFF".str_repeat('a', 600))->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $event = $this->securityEvents()->for($user)->sole();

    expect($event->user_agent)->toBe('Mozilla  /5.0?'.str_repeat('a', 498))
        ->and($this->securityEvents()->logged()[0]['user_agent'])->toBe($event->user_agent);
});

it('records a refused sign-in inside the timing floor', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);
    $this->travelTo(now()->startOfSecond()->addMilliseconds(800));
    $startedAt = now();

    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    Sleep::assertSequence([Sleep::for(300)->milliseconds()]);
    expect($this->securityEvents()->for($user)->sole()->occurred_at->equalTo($startedAt))->toBeTrue();
});

it('still signs in and writes the row and the event when the log channel fails', function () {
    Event::fake([SecurityEventRecorded::class]);
    config(['logging.channels.broken' => ['driver' => 'single', 'path' => '/dev/null/keystone.log'], 'keystone.log_channel' => 'broken']);
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignedIn($response, '/');
    expect($this->securityEvents()->for($user)->sole()->type)->toBe(SecurityEventType::SIGNED_IN);
    Event::assertDispatched(SecurityEventRecorded::class);
    Exceptions::assertReported(UnexpectedValueException::class);
});

it('still signs in and writes the log line and the event when the row cannot be written', function () {
    Event::fake([SecurityEventRecorded::class]);
    DB::beforeExecuting(function (string $query) {
        if (str_starts_with($query, 'insert') && str_contains($query, (new UserSecurityEvent)->getTable())) {
            throw new RuntimeException('The database refused the insert.');
        }
    });

    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignedIn($response, '/');
    expect($this->securityEvents()->for($user))->toBeEmpty()
        ->and($this->securityEvents()->logged()[0]['type'])->toBe('signed_in');

    Event::assertDispatched(SecurityEventRecorded::class, fn (SecurityEventRecorded $recorded) => $recorded->entry->user_id === $user->getAuthIdentifier());
    Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'The database refused the insert.');
});

it('still signs in and records when an app listener fails', function () {
    Event::listen(SecurityEventRecorded::class, fn () => throw new RuntimeException('The listener failed.'));
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignedIn($response, '/');
    expect($this->securityEvents()->for($user)->sole()->type)->toBe(SecurityEventType::SIGNED_IN)
        ->and($this->securityEvents()->logged()[0]['type'])->toBe('signed_in');

    Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'The listener failed.');
});

it('still signs in and records nothing when the entry cannot be built', function () {
    Event::fake([SecurityEventRecorded::class]);
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);
    $encrypter = Mockery::mock(Crypt::getFacadeRoot())->makePartial();
    $encrypter->shouldReceive('encrypt')->once()->andThrow(new RuntimeException('The encryption key is lost.'));
    Model::encryptUsing($encrypter);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignedIn($response, '/');
    expect($this->securityEvents()->stored())->toBeEmpty()
        ->and($this->securityEvents()->logged())->toBe([]);

    Event::assertNotDispatched(SecurityEventRecorded::class);
    Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'The encryption key is lost.');
});

it("reads the address the app's trusted proxy forwarded", function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);
    TrustProxies::at('10.0.0.1');

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->withHeader('X-Forwarded-For', '203.0.113.9')->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    expect($this->securityEvents()->for($user)->sole()->ip_address)->toBe('203.0.113.9');
});
