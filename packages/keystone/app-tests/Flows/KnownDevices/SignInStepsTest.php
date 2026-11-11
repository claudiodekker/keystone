<?php

use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->withoutEnrollmentMandates();

    Notification::fake();
});

afterEach(function () {
    Model::encryptUsing(null);
});

it('knows the browser whose sign-in the challenge completed', function () {
    $user = $this->createUser();
    $support = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);

    $this->keepCookiesFrom($this->answerChallenge($user, $support));

    $this->post(route('logout'));
    $this->travel(5)->minutes();
    $this->passFirstFactor($user);

    $response = $this->answerChallenge($user, $support);

    $this->assertSignedIn($response, '/');
    expect($this->securityEvents()->for($user)->where('type', SecurityEventType::SIGNED_IN)->pluck('known_device')->all())->toBe([false, true]);
});

it('knows the browser whose sign-in enrollment completed', function () {
    config(['keystone.require_recovery_codes' => true, 'keystone.require_second_factor' => true]);
    $user = $this->createUser();
    $support = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);
    $this->answerChallenge($user, $support);
    $codes = $this->shownRecoveryCodes($this->get(route('login.enrollment')));

    $this->keepCookiesFrom($this->post(route('login.enrollment.submit', 'recovery-code'), ['recovery_code' => $codes[3]]));

    $this->post(route('logout'));
    $this->passFirstFactor($user);

    $response = $this->post(route('login.challenge.submit', 'recovery-code'), ['recovery_code' => $codes[6]]);

    $this->assertSignedIn($response, '/');
    expect($this->securityEvents()->for($user)->where('type', SecurityEventType::SIGNED_IN)->pluck('known_device')->all())->toBe([false, true]);
});

it('leaves the browser signed out when the device it signs in from cannot be written', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);
    $encrypter = Mockery::mock(Crypt::getFacadeRoot())->makePartial();
    $encrypter->shouldReceive('encrypt')->once()->andThrow(new RuntimeException('The encryption key is lost.'));
    Model::encryptUsing($encrypter);

    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    Auth::forgetGuards();
    $next = $this->get(route('login'));

    $this->assertSignInPage($next, ['status' => null]);
    expect($this->securityEvents()->for($user)->where('type', SecurityEventType::SIGNED_IN))->toBeEmpty();
    Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'The encryption key is lost.');
});
