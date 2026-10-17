<?php

use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use Illuminate\Http\Middleware\TrustProxies;

afterEach(function () {
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
