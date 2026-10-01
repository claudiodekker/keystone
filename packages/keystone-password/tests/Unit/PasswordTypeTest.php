<?php

use ClaudioDekker\Keystone\Methods\InitiateShape;
use ClaudioDekker\Keystone\Methods\StoredCredential;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Password\PasswordType;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
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

    it('asks for at least 8 characters by default', function (Surface $surface, string $password, bool $passes) {
        $passed = passwordRulesPass($surface, $password);

        expect($passed)->toBe($passes);
    })->with([Surface::REGISTRATION, Surface::ENROLLMENT])->with([
        '7 characters' => [str_repeat('a', 7), false],
        '8 characters' => [str_repeat('a', 8), true],
    ]);

    it('applies the app\'s own password defaults', function (Surface $surface, string $password, bool $passes) {
        Password::defaults(fn () => Password::min(10));
        $this->beforeApplicationDestroyed(fn () => Password::defaults(fn () => Password::min(8)));

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
