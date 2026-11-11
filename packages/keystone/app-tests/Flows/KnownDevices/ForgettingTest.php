<?php

use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->withoutEnrollmentMandates();

    Notification::fake();
});

it("alerts at the owner's next sign-in once the operator ended the account's sessions", function (Closure $endSessions) {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $this->keepCookiesFrom($this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]));

    $endSessions($user);
    Auth::forgetGuards();

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignedIn($response, '/');
    expect($this->sentAlerts()->pluck('alert.type')->filter(fn (SecurityEventType $type) => $type === SecurityEventType::SIGNED_IN)->values()->all())->toBe([SecurityEventType::SIGNED_IN, SecurityEventType::SIGNED_IN]);
})->with([
    "ending the account's sessions" => [fn (KeystoneUser $user) => Artisan::call('keystone:end-sessions', ['user' => $user->getAuthIdentifier(), '--operator' => 'margaret'])],
    'ending them without an alert' => [fn (KeystoneUser $user) => Artisan::call('keystone:end-sessions', ['user' => $user->getAuthIdentifier(), '--operator' => 'margaret', '--no-alert' => true])],
    "ending every account's sessions" => [fn () => Artisan::call('keystone:end-sessions', ['--all' => true, '--operator' => 'margaret'])],
]);

it('keeps knowing the browser through a suspension, which ends sessions without saying a browser was taken', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $this->keepCookiesFrom($this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]));

    Artisan::call('keystone:suspend', ['user' => $user->getAuthIdentifier(), '--operator' => 'margaret']);
    Artisan::call('keystone:unsuspend', ['user' => $user->getAuthIdentifier(), '--operator' => 'margaret']);
    Auth::forgetGuards();

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignedIn($response, '/');
    expect($this->sentAlerts()->pluck('alert.type')->filter(fn (SecurityEventType $type) => $type === SecurityEventType::SIGNED_IN))->toHaveCount(1);
});
