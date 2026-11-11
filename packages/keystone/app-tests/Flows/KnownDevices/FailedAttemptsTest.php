<?php

use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->withoutEnrollmentMandates();

    Notification::fake();
});

it("lets a browser the account knows sign in after other browsers spent the account's failed-attempt allowance", function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $this->keepCookiesFrom($this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]));

    $this->post(route('logout'));
    $owner = $this->switchToFreshBrowser();
    $this->submitRejectedProofs($support, 'ada@example.com', $this->failedAttemptAllowance() + 1);
    $this->switchBackTo($owner);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignedIn($response, '/');
});

it('refuses a browser the account knows once it spent its own failed-attempt allowance', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $this->keepCookiesFrom($this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]));

    $this->post(route('logout'));
    $this->submitRejectedProofs($support, 'ada@example.com', $this->failedAttemptAllowance());

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignInThrottled($response);
});

it("counts a browser only another account knows with the other browsers, once they spent the account's allowance", function () {
    $ada = $this->createUser();
    $this->addVerifiedAddress($ada, 'ada@example.com');
    $grace = $this->createUser();
    $this->addVerifiedAddress($grace, 'grace@example.com');
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $ada);
    $support->arrangeCredential(Surface::SIGN_IN, $grace);

    $this->keepCookiesFrom($this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'grace@example.com',
        ...$support->validProof(Surface::SIGN_IN, $grace),
    ]));

    $this->post(route('logout'));
    $graces = $this->switchToFreshBrowser();
    $this->submitRejectedProofs($support, 'ada@example.com', $this->failedAttemptAllowance());
    $this->switchBackTo($graces);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $ada),
    ]);

    $this->assertSignInThrottled($response);
});

it("counts a copy of the device cookie with the other browsers once the owner's browser replaced it", function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $this->keepCookiesFrom($this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]));

    $copy = $this->browserWithCopiedCookies();
    $this->post(route('logout'));

    $this->keepCookiesFrom($this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]));

    $this->switchToFreshBrowser();
    $this->submitRejectedProofs($support, 'ada@example.com', $this->failedAttemptAllowance());
    $this->switchBackTo($copy);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignInThrottled($response);
});

it('counts a browser the account has not signed in from for the retention with the other browsers', function () {
    config(['keystone.retention.known_devices_seconds' => 17 * 24 * 60 * 60]);
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $this->keepCookiesFrom($this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]));

    $this->post(route('logout'));
    $this->travel(17 * 24 * 60 * 60)->seconds();
    $owner = $this->switchToFreshBrowser();
    $this->submitRejectedProofs($support, 'ada@example.com', $this->failedAttemptAllowance());
    $this->switchBackTo($owner);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignInThrottled($response);
});
