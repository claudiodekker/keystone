<?php

use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use ClaudioDekker\Keystone\Totp\AppTests\TotpSupport;

beforeEach(function () {
    $this->withoutEnrollmentMandates();
});

it('accepts the code of the step before and after now by default', function (int $steps) {
    $user = $this->createUser();
    $support = (new TotpSupport)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);

    $response = $this->post(route('login.challenge.submit', 'totp'), ['code' => $support->code($steps)]);

    $this->assertSignedIn($response, '/');
})->with([
    'the step before' => [-1],
    'the step after' => [1],
]);

it('refuses the code of a step two away by default', function (int $steps) {
    $user = $this->createUser();
    $support = (new TotpSupport)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);

    $response = $this->post(route('login.challenge.submit', 'totp'), ['code' => $support->code($steps)]);

    $this->assertChallengeRefused($response, 'code', __('keystone::messages.credential-invalid'));
})->with([
    'two steps before' => [-2],
    'two steps after' => [2],
]);

it('refuses the code of the step before or after now with a window of 0', function (int $steps) {
    config(['keystone.totp.window_steps' => 0]);
    $user = $this->createUser();
    $support = (new TotpSupport)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);

    $response = $this->post(route('login.challenge.submit', 'totp'), ['code' => $support->code($steps)]);

    $this->assertChallengeRefused($response, 'code', __('keystone::messages.credential-invalid'));
})->with([
    'the step before' => [-1],
    'the step after' => [1],
]);

it('accepts the code of the current step with a window of 0', function () {
    config(['keystone.totp.window_steps' => 0]);
    $user = $this->createUser();
    $support = (new TotpSupport)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);

    $response = $this->post(route('login.challenge.submit', 'totp'), ['code' => $support->code()]);

    $this->assertSignedIn($response, '/');
});

it('signs in once with the same code sent from two held sessions', function () {
    $user = $this->createUser();
    $support = (new TotpSupport)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);
    $first = $this->switchToFreshBrowser();
    $this->passFirstFactor($user);

    $second = $this->post(route('login.challenge.submit', 'totp'), ['code' => $support->code()]);

    $this->switchBackTo($first);
    $replayed = $this->post(route('login.challenge.submit', 'totp'), ['code' => $support->code()]);

    $this->assertSignedIn($second, '/');
    $this->assertChallengeRefused($replayed, 'code', __('keystone::messages.credential-invalid'));
});

it('refuses a code from a step before the last one accepted, recording it replayed', function () {
    $user = $this->createUser();
    $support = (new TotpSupport)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);
    $this->post(route('login.challenge.submit', 'totp'), ['code' => $support->code(1)]);

    $this->switchToFreshBrowser();
    $this->passFirstFactor($user);
    $response = $this->post(route('login.challenge.submit', 'totp'), ['code' => $support->code()]);

    $rejected = $this->securityEvents()->for($user)->last();

    $this->assertChallengeRefused($response, 'code', __('keystone::messages.credential-invalid'));
    expect($rejected->type)->toBe(SecurityEventType::PROOF_REJECTED)
        ->and($rejected->reason)->toBe('totp.replayed');
});

it('accepts the next step after a code was accepted', function () {
    $user = $this->createUser();
    $support = (new TotpSupport)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);
    $this->post(route('login.challenge.submit', 'totp'), ['code' => $support->code()]);

    $this->switchToFreshBrowser();
    $this->passFirstFactor($user);
    $response = $this->post(route('login.challenge.submit', 'totp'), ['code' => $support->code(1)]);

    $this->assertSignedIn($response, '/');
});

it('refuses a code that is not six digits as invalid, before counting it', function (string $code) {
    $user = $this->createUser();
    (new TotpSupport)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);

    $response = $this->post(route('login.challenge.submit', 'totp'), ['code' => $code]);

    $this->assertChallengeInvalid($response, ['code']);
    expect($this->securityEvents()->for($user)->pluck('type')->all())->toBe([SecurityEventType::SIGN_IN_HELD]);
})->with([
    'five digits' => ['12345'],
    'letters' => ['12a456'],
]);
