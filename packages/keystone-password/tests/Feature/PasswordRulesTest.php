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

describe('context words', function () {
    it('refuses a password containing a context word, whatever its case', function (string $password) {
        config(['app.name' => 'Acme Payroll', 'app.url' => 'https://portal.example.test', 'keystone-password.context_words' => ['Keystone']]);
        $account = signInRequiringSecondFactor($this, 'jane.doe@example.com');
        $this->holdAddress($account, 'jdoe.work@example.org');
        $this->holdAddress($account, 'mallory@example.org', verified: false);

        $response = submitNewPassword($this, $password);

        $response->assertSessionHasErrors(['password' => __('keystone-password::messages.context_word', ['attribute' => 'password'])]);
    })->with([
        'a word of the app\'s name' => ['my PAYROLL 2026!'],
        'a word of the app\'s host' => ['portal-is-mine-2026'],
        'a word of the email\'s local part' => ['Jane rules 2026!'],
        'a word of another address the account holds' => ['jdoe-2026-rocks'],
        'a word of an unverified address' => ['MALLORY-2026!'],
        'a configured context word' => ['ilovekeystone99'],
    ]);

    it('lets a password contain a context word shorter than 4 characters', function () {
        config(['app.name' => 'Hub']);
        signInRequiringSecondFactor($this, 'al@example.com');

        $response = submitNewPassword($this, 'hub al rocks 2026');

        assertReachedVerification($response);
    });

    it('never counts the email\'s domain as a context word', function () {
        signInRequiringSecondFactor($this, 'jane@gmail.com');

        $response = submitNewPassword($this, 'gmailgmail99');

        assertReachedVerification($response);
    });
});

describe('the common-password list', function () {
    it('refuses a password on the bundled list, whatever its case', function (string $password) {
        signInRequiringSecondFactor($this);

        $response = submitNewPassword($this, $password);

        $response->assertSessionHasErrors(['password' => __('keystone-password::messages.common', ['attribute' => 'password'])]);
    })->with([
        'password' => ['password'],
        'PassWord' => ['PassWord'],
        '12345678' => ['12345678'],
        'ILOVEYOU' => ['ILOVEYOU'],
    ]);
});
