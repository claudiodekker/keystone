<?php

use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Notifications\SecurityAlert;
use ClaudioDekker\Keystone\SecurityEvents\Actor;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Sleep;

it("refuses a suspended account's right secret exactly like a wrong one", function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    Artisan::call('keystone:suspend', ['user' => $user->getAuthIdentifier(), '--operator' => 'margaret']);

    $wrongSecret = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    $this->assertSignInRefused($wrongSecret, __('keystone::messages.credentials-rejected'));

    $this->switchToFreshBrowser();
    $rightSecret = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignInRefused($rightSecret, __('keystone::messages.credentials-rejected'));
    $this->assertIndistinguishable($wrongSecret, $rightSecret);
    Sleep::assertSequence([Sleep::for(300)->milliseconds(), Sleep::for(300)->milliseconds()]);
});

it('signs out a session that signed in before the suspension on its next request', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    Artisan::call('keystone:suspend', ['user' => $user->getAuthIdentifier(), '--operator' => 'margaret']);

    Auth::forgetGuards();
    $response = $this->get(route('login'));

    $this->assertSignInPage($response, ['status' => __('keystone::messages.session-ended')]);
});

it("signs out a session on the account's credential epoch once the account is suspended", function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $user->forceFill(['suspended_at' => now()])->save();

    Auth::forgetGuards();
    $response = $this->get(route('login'));

    $this->assertSignInPage($response, ['status' => __('keystone::messages.session-ended')]);
});

it('keeps holding the addresses a suspended account verified, so another account that holds one unverified is not signed in by it', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'shared@example.com');
    $other = $this->createUser();
    $this->addUnverifiedAddress($other, 'shared@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user)->arrangeCredential(Surface::SIGN_IN, $other);

    Artisan::call('keystone:suspend', ['user' => $user->getAuthIdentifier(), '--operator' => 'margaret']);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'shared@example.com',
        ...$support->validProof(Surface::SIGN_IN, $other),
    ]);

    $this->assertSignInRefused($response, __('keystone::messages.credentials-rejected'));
});

it('records account.suspended by the operator who suspended the account, and alerts its owner', function () {
    Notification::fake();
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');

    Artisan::call('keystone:suspend', ['user' => $user->getAuthIdentifier(), '--operator' => 'margaret']);

    $events = $this->securityEvents()->for($user);
    $alerts = $this->sentAlerts();

    expect($events->pluck('type')->all())->toBe([SecurityEventType::ACCOUNT_SUSPENDED])
        ->and($events[0]->actor)->toBe(Actor::OPERATOR)
        ->and($events[0]->operator)->toBe('margaret')
        ->and($alerts->pluck('address')->all())->toBe(['ada@example.com'])
        ->and($alerts[0]['alert'])->toBeInstanceOf(SecurityAlert::class)
        ->and($alerts[0]['alert']->type)->toBe(SecurityEventType::ACCOUNT_SUSPENDED);
});

it('signs the account in again with the secret it already had once it is unsuspended', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    Artisan::call('keystone:suspend', ['user' => $user->getAuthIdentifier(), '--operator' => 'margaret']);
    Artisan::call('keystone:unsuspend', ['user' => $user->getAuthIdentifier(), '--operator' => 'margaret']);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    Auth::forgetGuards();
    $next = $this->get(route('login'));

    $this->assertSignedIn($response, '/');
    $this->assertAlreadySignedIn($next);
});

it('records account.unsuspended by the operator who lifted the suspension, and alerts the owner', function () {
    Notification::fake();
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');

    Artisan::call('keystone:suspend', ['user' => $user->getAuthIdentifier(), '--operator' => 'margaret']);
    Artisan::call('keystone:unsuspend', ['user' => $user->getAuthIdentifier(), '--operator' => 'grace']);

    $events = $this->securityEvents()->for($user);
    $alerts = $this->sentAlerts();

    expect($events->pluck('type')->all())->toBe([SecurityEventType::ACCOUNT_SUSPENDED, SecurityEventType::ACCOUNT_UNSUSPENDED])
        ->and($events[1]->actor)->toBe(Actor::OPERATOR)
        ->and($events[1]->operator)->toBe('grace')
        ->and($alerts->pluck('address')->all())->toBe(['ada@example.com', 'ada@example.com'])
        ->and($alerts[1]['alert'])->toBeInstanceOf(SecurityAlert::class)
        ->and($alerts[1]['alert']->type)->toBe(SecurityEventType::ACCOUNT_UNSUSPENDED);
});
