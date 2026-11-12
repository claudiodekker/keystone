<?php

use ClaudioDekker\Keystone\RateLimits\StepKind;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use ClaudioDekker\Keystone\Session\SessionState;
use ClaudioDekker\Keystone\Status;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    $this->withoutEnrollmentMandates();
});

it('ends a live grant, recording it, and leaves the revoked status', function () {
    $user = $this->createUser();
    $this->passFirstFactor($user);
    $gated = $this->sudoGatedPage();

    Auth::forgetGuards();
    $response = $this->delete(route('sudo.end'));

    $status = app(SessionState::class)->status();

    Auth::forgetGuards();
    $next = $this->getJson($gated);

    $this->assertSudoEnded($response);
    $this->assertSudoRequiredForJson($next);
    expect($status)->toBe(Status::SUDO_REVOKED)
        ->and($this->securityEvents()->for($user)->last()->type)->toBe(SecurityEventType::SUDO_REVOKED);
});

it('records nothing more when the request is repeated', function () {
    $user = $this->createUser();
    $this->passFirstFactor($user);

    Auth::forgetGuards();
    $this->delete(route('sudo.end'));

    Auth::forgetGuards();
    $this->delete(route('sudo.end'));

    expect($this->securityEvents()->for($user)->where('type', SecurityEventType::SUDO_REVOKED))->toHaveCount(1);
});

it('ends a sudo-in-progress too, recording nothing', function () {
    $user = $this->createUser();
    $this->signInWithoutSudo($user);
    $this->get($this->sudoGatedPage());

    Auth::forgetGuards();
    $this->delete(route('sudo.end'));

    Auth::forgetGuards();
    $page = $this->get(route('sudo'));

    $this->assertNoSudoInProgress($page, '/');
    expect($this->securityEvents()->for($user)->where('type', SecurityEventType::SUDO_REVOKED))->toBeEmpty();
});

it('gives the session a new id when it ends sudo', function () {
    $user = $this->createUser();
    $this->passFirstFactor($user);
    $cookie = config('session.cookie');

    $before = $this->get(route('login'))->getCookie($cookie)->getValue();

    Auth::forgetGuards();
    $response = $this->withCookie($cookie, $before)->delete(route('sudo.end'));

    expect($response->getCookie($cookie)->getValue())->not->toBe($before);
});

it('turns a guest away', function () {
    $response = $this->deleteJson(route('sudo.end'));

    expect($response->getStatusCode())->toBe(401);
});

it('limits ending sudo as a change', function () {
    $user = $this->createUser();
    $this->passFirstFactor($user);

    foreach (range(1, $this->requestAllowance(StepKind::CHANGE)) as $request) {
        Auth::forgetGuards();
        $this->delete(route('sudo.end'));
    }

    Auth::forgetGuards();
    $response = $this->delete(route('sudo.end'));

    $this->assertThrottled($response);
});
