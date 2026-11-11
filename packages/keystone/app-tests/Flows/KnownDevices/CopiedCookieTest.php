<?php

use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->withoutEnrollmentMandates();

    Notification::fake();
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
