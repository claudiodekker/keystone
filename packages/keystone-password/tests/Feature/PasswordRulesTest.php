<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\KeystoneUser;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Tests\Fixtures\FormTypeSupport;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\Rules\Password;

pest()->extend(AppTestCase::class);

beforeEach(function () {
    Exceptions::fake();
    config(['keystone.require_recovery_codes' => false]);
});

function signInRequiringSecondFactor(AppTestCase $test, string $address = 'jane.doe@example.com'): Model&KeystoneUser
{
    config(['keystone.require_second_factor' => true]);
    $challenge = new FormTypeSupport('code');
    $account = $test->createChallengedAccount($challenge, $address);
    $test->passFirstFactor($address);
    $test->post(route('login.challenge.submit', ['type' => 'code']), $challenge->validProof(Surface::CHALLENGE));

    return $account;
}

function signInWithoutSecondFactor(AppTestCase $test, string $address = 'jane.doe@example.com'): Model&KeystoneUser
{
    config(['keystone.require_second_factor' => false]);
    $account = $test->createFirstFactorAccount($address);
    $test->passFirstFactor($address);

    return $account;
}

function submitNewPassword(AppTestCase $test, string $password): TestResponse
{
    $test->get(route('security.enroll', ['type' => 'password']));

    return $test->post(route('security.enroll.submit', ['type' => 'password']), ['password' => $password, 'password_confirmation' => $password]);
}

function assertReachedVerification(TestResponse $response): void
{
    $response->assertSessionHasErrors(['password' => __('keystone::messages.invalid_credential')]);
}

describe('the minimum length', function () {
    it('asks for at least 8 characters while a second factor is required', function (string $password, bool $passes) {
        signInRequiringSecondFactor($this);

        $response = submitNewPassword($this, $password);

        $passes
            ? assertReachedVerification($response)
            : $response->assertSessionHasErrors(['password' => __('validation.min.string', ['attribute' => 'password', 'min' => 8])]);
    })->with([
        '7 characters' => ['tq8#vbn', false],
        '8 characters' => ['tq8#vbnz', true],
    ]);

    it('asks for at least 15 characters when a password may be the only factor', function (string $password, bool $passes) {
        signInWithoutSecondFactor($this);

        $response = submitNewPassword($this, $password);

        $passes
            ? assertReachedVerification($response)
            : $response->assertSessionHasErrors(['password' => __('validation.min.string', ['attribute' => 'password', 'min' => 15])]);
    })->with([
        '14 characters' => ['tq8#vbnz-wx4!k', false],
        '15 characters' => ['tq8#vbnz-wx4!kp', true],
    ]);

    it('asks for the app\'s own minimum for the mandate in force', function (bool $requireSecondFactor, string $key, string $password, bool $passes) {
        config(["keystone-password.min_length.{$key}" => 12]);
        $requireSecondFactor ? signInRequiringSecondFactor($this) : signInWithoutSecondFactor($this);

        $response = submitNewPassword($this, $password);

        $passes
            ? assertReachedVerification($response)
            : $response->assertSessionHasErrors(['password' => __('validation.min.string', ['attribute' => 'password', 'min' => 12])]);
    })->with([
        'required, 11 characters' => [true, 'second_factor_required', 'tq8#vbnz-wx', false],
        'required, 12 characters' => [true, 'second_factor_required', 'tq8#vbnz-wx4', true],
        'optional, 11 characters' => [false, 'second_factor_optional', 'tq8#vbnz-wx', false],
        'optional, 12 characters' => [false, 'second_factor_optional', 'tq8#vbnz-wx4', true],
    ]);

    it('counts characters, not bytes', function () {
        signInRequiringSecondFactor($this);

        $response = submitNewPassword($this, 'ééééçççç');

        assertReachedVerification($response);
    });

    it('ignores the app\'s own password defaults', function () {
        Password::defaults(fn () => Password::min(20)->symbols());
        $this->beforeApplicationDestroyed(fn () => Password::$defaultCallback = null);
        signInRequiringSecondFactor($this);

        $response = submitNewPassword($this, 'tq8vbnzw');

        assertReachedVerification($response);
    });
});
