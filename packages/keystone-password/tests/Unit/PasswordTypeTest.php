<?php

use ClaudioDekker\Keystone\BootChecks;
use ClaudioDekker\Keystone\Exceptions\Misconfigured;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\InitiateShape;
use ClaudioDekker\Keystone\Methods\StoredCredential;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Password\PasswordType;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Mockery\MockInterface;

function passwordCredential(string $secret, int $id = 1): StoredCredential
{
    return new StoredCredential($id, identifier: null, secret: $secret, label: null);
}

function spyOnHash(): MockInterface
{
    $hash = Mockery::mock(app('hash'));
    Hash::swap($hash);

    return $hash;
}

function passwordBootFailures(): array
{
    try {
        (new BootChecks(app(CredentialTypes::class)))->check();
    } catch (Misconfigured $e) {
        return $e->failures;
    }

    return [];
}

function passwordRulesPass(Surface $surface, mixed $password, mixed $confirmation = null): bool
{
    $input = ['password' => $password, 'password_confirmation' => func_num_args() > 2 ? $confirmation : $password];

    return Validator::make($input, (new PasswordType)->rules($surface))->passes();
}

test('the type serves sign-in, registration and enrollment with a form, and is not multi-factor on its own', function () {
    $type = new PasswordType;

    expect($type->name())->toBe('password')
        ->and($type->surfaces())->toBe([
            'sign-in' => InitiateShape::FORM,
            'registration' => InitiateShape::FORM,
            'enrollment' => InitiateShape::FORM,
        ])
        ->and($type->representsMultipleFactors())->toBeFalse();
});

describe('new password rules', function () {
    it('requires a string password', function (Surface $surface, mixed $password, bool $passes) {
        $passed = passwordRulesPass($surface, $password);

        expect($passed)->toBe($passes);
    })->with([Surface::REGISTRATION, Surface::ENROLLMENT])->with([
        'missing' => [null, false],
        'empty' => ['', false],
        'an array' => [['secret'], false],
        'a string' => ['correct horse battery staple', true],
    ]);

    it('requires a new password to be confirmed', function (Surface $surface, mixed $confirmation, bool $passes) {
        $passed = passwordRulesPass($surface, 'correct horse battery staple', $confirmation);

        expect($passed)->toBe($passes);
    })->with([Surface::REGISTRATION, Surface::ENROLLMENT])->with([
        'missing' => [null, false],
        'different' => ['correct horse battery staple ', false],
        'the same' => ['correct horse battery staple', true],
    ]);

    it('asks for at least 8 characters while a second factor is required, and 15 while it isn\'t', function (Surface $surface, bool $required, string $password, bool $passes) {
        config(['keystone.require_second_factor' => $required]);

        $passed = passwordRulesPass($surface, $password);

        expect($passed)->toBe($passes);
    })->with([Surface::REGISTRATION, Surface::ENROLLMENT])->with([
        '7 with a second factor' => [true, str_repeat('a', 7), false],
        '8 with a second factor' => [true, str_repeat('a', 8), true],
        '14 alone' => [false, str_repeat('a', 14), false],
        '15 alone' => [false, str_repeat('a', 15), true],
    ]);

    it('asks for the app\'s own minimum length in characters, whatever the mandate', function (Surface $surface, string $password, bool $passes) {
        config(['keystone-password.min_length' => 10, 'keystone.require_second_factor' => false]);

        $passed = passwordRulesPass($surface, $password);

        expect($passed)->toBe($passes);
    })->with([Surface::REGISTRATION, Surface::ENROLLMENT])->with([
        '9 characters' => [str_repeat('é', 9), false],
        '10 characters' => [str_repeat('é', 10), true],
    ]);

    it('caps a new password at 72 bytes under bcrypt', function (Surface $surface, string $password, bool $passes) {
        config(['hashing.driver' => 'bcrypt']);

        $passed = passwordRulesPass($surface, $password);

        expect($passed)->toBe($passes);
    })->with([Surface::REGISTRATION, Surface::ENROLLMENT])->with([
        '72 bytes' => [str_repeat('a', 72), true],
        '73 bytes' => [str_repeat('a', 73), false],
        '36 two-byte characters' => [str_repeat('é', 36), true],
        '37 two-byte characters' => [str_repeat('é', 37), false],
    ]);

    it('caps a new password at the app\'s bcrypt limit when it is lower', function (Surface $surface, string $password, bool $passes) {
        config(['hashing.driver' => 'bcrypt', 'hashing.bcrypt.limit' => 64]);

        $passed = passwordRulesPass($surface, $password);

        expect($passed)->toBe($passes);
    })->with([Surface::REGISTRATION, Surface::ENROLLMENT])->with([
        '64 bytes' => [str_repeat('a', 64), true],
        '65 bytes' => [str_repeat('a', 65), false],
    ]);

    it('caps a new password at 1024 characters under argon', function (Surface $surface, string $driver, string $password, bool $passes) {
        config(['hashing.driver' => $driver]);

        $passed = passwordRulesPass($surface, $password);

        expect($passed)->toBe($passes);
    })->with([Surface::REGISTRATION, Surface::ENROLLMENT])->with(['argon', 'argon2id'])->with([
        '1024 two-byte characters' => [str_repeat('é', 1024), true],
        '1025 characters' => [str_repeat('a', 1025), false],
    ]);
});

describe('the minimum length', function () {
    it('boots with no minimum length, or one of at least 8', function (?int $minLength) {
        config(['keystone-password.min_length' => $minLength]);

        expect(passwordBootFailures())->toBe([]);
    })->with(['null' => [null], 'the floor' => [8], 'a long one' => [64]]);

    it('refuses to boot with a minimum length under 8 or not a whole number', function (mixed $minLength) {
        config(['keystone-password.min_length' => $minLength]);

        expect(passwordBootFailures())->toBe(['keystone-password.min_length must be null or a whole number of at least 8.']);
    })->with(['under the floor' => [7], 'zero' => [0], 'a string' => ['12'], 'a fraction' => [8.5]]);
});

describe('verify', function () {
    it('checks a password against the dummy hash when the subject holds none, as it would a real one', function (array $credentials) {
        $hash = spyOnHash();
        $hash->shouldReceive('check')->once()->passthru();

        (new PasswordType)->verify(Surface::SIGN_IN, ['password' => 'correct horse'], $credentials);
    })->with([
        'no password' => [[]],
        'an unknown format' => [[passwordCredential('not a hash')]],
    ]);

    it('makes the dummy hash with the app\'s driver and cost once, until the hashing config changes', function () {
        $hash = spyOnHash();
        $hash->shouldReceive('make')->twice()->passthru();
        $type = new PasswordType;

        $type->verify(Surface::SIGN_IN, ['password' => 'one'], []);
        $type->verify(Surface::SIGN_IN, ['password' => 'two'], []);
        config(['hashing.bcrypt.rounds' => 5]);
        $type->verify(Surface::SIGN_IN, ['password' => 'three'], []);
    });

    it('makes the new hash only when core asks for it', function () {
        $credential = passwordCredential(password_hash('correct horse', PASSWORD_BCRYPT, ['cost' => 5]));
        $hash = spyOnHash();
        $hash->shouldReceive('make')->never();

        (new PasswordType)->verify(Surface::SIGN_IN, ['password' => 'correct horse'], [$credential]);
    });

    it('refuses to verify on a surface it has no flow for yet', function (Surface $surface) {
        (new PasswordType)->verify($surface, ['password' => Str::random()], []);
    })->with([Surface::REGISTRATION, Surface::ENROLLMENT])->throws(LogicException::class);
});
