<?php

use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Notifications\SecurityAlert;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->withoutEnrollmentMandates();

    Notification::fake();
});

it('alerts the owner when the account signs in from a browser it has not seen', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignedIn($response, '/');

    $alerts = $this->sentAlerts();

    expect($alerts->pluck('address')->all())->toBe(['ada@example.com'])
        ->and($alerts[0]['alert'])->toBeInstanceOf(SecurityAlert::class)
        ->and($alerts[0]['alert']->type)->toBe(SecurityEventType::SIGNED_IN)
        ->and($this->securityEvents()->for($user)->where('type', SecurityEventType::SIGNED_IN)->pluck('known_device')->all())->toBe([false]);
});

it('sends no alert when the account signs in again from a browser it knows', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $first = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->keepCookiesFrom($first);
    $this->post(route('logout'));

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignedIn($response, '/');
    expect($this->sentAlerts())->toHaveCount(1)
        ->and($this->securityEvents()->for($user)->where('type', SecurityEventType::SIGNED_IN)->pluck('known_device')->all())->toBe([false, true]);
});

it('hands the browser a host-only device cookie that only HTTPS carries, scripts never read and cross-site requests leave behind, for the retention', function () {
    config(['keystone.retention.known_devices_seconds' => 41 * 24 * 60 * 60]);
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $cookie = $response->getCookie('__Host-keystone_device', decrypt: false);

    $this->assertSignedIn($response, '/');
    expect($cookie->getValue())->toMatch('/^[0-9a-f]{32}\.[0-9a-f]{64}$/')
        ->and($cookie->getDomain())->toBeNull()
        ->and($cookie->getPath())->toBe('/')
        ->and($cookie->isSecure())->toBeTrue()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax')
        ->and($cookie->getExpiresTime())->toBe(now()->getTimestamp() + 3542400);
});

it('sends no alert when a browser the account knows signs in from a new network', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $first = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.23'])->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->keepCookiesFrom($first);
    $this->post(route('logout'));

    $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.71'])->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignedIn($response, '/');
    expect($this->sentAlerts())->toHaveCount(1);
});

it('alerts the owner when a browser the account has not seen signs in from a network it has', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $first = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.23'])->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->keepCookiesFrom($first);
    $this->switchToFreshBrowser();

    $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.23'])->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignedIn($response, '/');
    expect($this->sentAlerts()->pluck('alert.type')->all())->toBe([SecurityEventType::SIGNED_IN, SecurityEventType::SIGNED_IN]);
});

it("alerts the owner when a copy of the device cookie signs in after the owner's browser replaced it", function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $first = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->keepCookiesFrom($first);
    $copy = $this->browserWithCopiedCookies();
    $this->post(route('logout'));

    $again = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->keepCookiesFrom($again);
    $this->switchBackTo($copy);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignedIn($response, '/');
    expect($this->sentAlerts()->pluck('alert.type')->all())->toBe([SecurityEventType::SIGNED_IN, SecurityEventType::DEVICE_COOKIE_REUSED])
        ->and($this->securityEvents()->for($user)->where('type', SecurityEventType::DEVICE_COOKIE_REUSED))->toHaveCount(1);
});

it("alerts the owner when the owner's browser signs in after a copy of its cookie signed in first", function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $first = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->keepCookiesFrom($first);
    $copy = $this->browserWithCopiedCookies();
    $this->post(route('logout'));
    $owner = $this->switchToFreshBrowser();
    $this->switchBackTo($copy);

    $fromCopy = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->keepCookiesFrom($fromCopy);
    $this->switchBackTo($owner);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignedIn($response, '/');
    expect($this->sentAlerts()->pluck('alert.type')->all())->toBe([SecurityEventType::SIGNED_IN, SecurityEventType::DEVICE_COOKIE_REUSED]);
});

it('leaves the device known to another account when a copy of its cookie signs in to one', function () {
    $ada = $this->createUser();
    $this->addVerifiedAddress($ada, 'ada@example.com');
    $grace = $this->createUser();
    $this->addVerifiedAddress($grace, 'grace@example.com');
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $ada);
    $support->arrangeCredential(Surface::SIGN_IN, $grace);

    $this->keepCookiesFrom($this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $ada),
    ]));

    $this->post(route('logout'));

    $this->keepCookiesFrom($this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'grace@example.com',
        ...$support->validProof(Surface::SIGN_IN, $grace),
    ]));

    $copy = $this->browserWithCopiedCookies();
    $this->post(route('logout'));

    $this->keepCookiesFrom($this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $ada),
    ]));

    $this->post(route('logout'));
    $owner = $this->switchToFreshBrowser();
    $this->switchBackTo($copy);

    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $ada),
    ]);

    $this->switchBackTo($owner);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'grace@example.com',
        ...$support->validProof(Surface::SIGN_IN, $grace),
    ]);

    $this->assertSignedIn($response, '/');
    expect($this->sentAlerts()->where('address', 'grace@example.com')->pluck('alert.type')->all())->toBe([SecurityEventType::SIGNED_IN]);
});
