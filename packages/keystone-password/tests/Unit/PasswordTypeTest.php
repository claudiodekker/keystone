<?php

use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\InitiateShape;
use ClaudioDekker\Keystone\Methods\Proof;
use ClaudioDekker\Keystone\Methods\StoredCredential;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Password\PasswordServiceProvider;
use ClaudioDekker\Keystone\Password\PasswordType;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Mockery\MockInterface;

function password(string $secret, int $id = 1): StoredCredential
{
    return new StoredCredential($id, null, $secret, null);
}

function spyOnHash(): MockInterface
{
    $hash = Mockery::mock(app('hash'));
    Hash::swap($hash);

    return $hash;
}

function passwordRulesPass(Surface $surface, mixed $password): bool
{
    return Validator::make(['password' => $password], (new PasswordType)->rules($surface))->passes();
}

describe('registration', function () {
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

    it('is registered for sign-in', function () {
        expect(app(CredentialTypes::class)->find('password', Surface::SIGN_IN))->toBeInstanceOf(PasswordType::class);
    });

    it('keeps a type the app bound in its place', function () {
        $custom = new class extends PasswordType {};
        $this->app->instance(PasswordType::class, $custom);

        (new PasswordServiceProvider($this->app))->register();

        expect($this->app->make(PasswordType::class))->toBe($custom);
    });
});

describe('rules', function () {
    it('requires a string password', function (Surface $surface, mixed $password, bool $passes) {
        expect(passwordRulesPass($surface, $password))->toBe($passes);
    })->with([Surface::SIGN_IN, Surface::REGISTRATION, Surface::ENROLLMENT])->with([
        'missing' => [null, false],
        'empty' => ['', false],
        'an array' => [['secret'], false],
        'a string' => ['secret', true],
    ]);

    it('caps a new password at 72 bytes under bcrypt', function (Surface $surface, string $password, bool $passes) {
        config(['hashing.driver' => 'bcrypt']);

        expect(passwordRulesPass($surface, $password))->toBe($passes);
    })->with([Surface::REGISTRATION, Surface::ENROLLMENT])->with([
        '72 bytes' => [str_repeat('a', 72), true],
        '73 bytes' => [str_repeat('a', 73), false],
        '36 two-byte characters' => [str_repeat('é', 36), true],
        '37 two-byte characters' => [str_repeat('é', 37), false],
    ]);

    it('caps a new password at 1024 characters under argon', function (Surface $surface, string $driver, string $password, bool $passes) {
        config(['hashing.driver' => $driver]);

        expect(passwordRulesPass($surface, $password))->toBe($passes);
    })->with([Surface::REGISTRATION, Surface::ENROLLMENT])->with(['argon', 'argon2id'])->with([
        '1024 two-byte characters' => [str_repeat('é', 1024), true],
        '1025 characters' => [str_repeat('a', 1025), false],
    ]);

    it('takes a long password at sign-in, so an imported hash of one still verifies', function () {
        config(['hashing.driver' => 'bcrypt']);

        expect(passwordRulesPass(Surface::SIGN_IN, str_repeat('a', 100)))->toBeTrue();
    });
});

describe('verify', function () {
    it('proves the password credential the typed password matches', function () {
        $credential = password(Hash::make('correct horse'));

        expect((new PasswordType)->verify(Surface::SIGN_IN, ['password' => 'correct horse'], [$credential]))
            ->toEqual(Proof::proven($credential));
    });

    it('rejects a wrong password, naming the credential it was checked against', function () {
        $credential = password(Hash::make('correct horse'));

        expect((new PasswordType)->verify(Surface::SIGN_IN, ['password' => 'wrong horse'], [$credential]))
            ->toEqual(Proof::rejected('password.mismatch', $credential));
    });

    it('verifies a hash with its own driver and rehashes it to the app\'s', function (string $from, string $to, string $algorithm) {
        config(['hashing.driver' => $from]);
        $credential = password(Hash::make('correct horse'));
        config(['hashing.driver' => $to]);
        app('hash')->forgetDrivers();

        $proof = (new PasswordType)->verify(Surface::SIGN_IN, ['password' => 'correct horse'], [$credential]);

        expect($proof->proven)->toBeTrue()
            ->and(password_get_info((string) $proof->updatedSecret)['algoName'])->toBe($algorithm)
            ->and(Hash::check('correct horse', (string) $proof->updatedSecret))->toBeTrue();
    })->with([
        'bcrypt to argon2id' => ['bcrypt', 'argon2id', 'argon2id'],
        'argon2i to argon2id' => ['argon', 'argon2id', 'argon2id'],
        'argon2id to bcrypt' => ['argon2id', 'bcrypt', 'bcrypt'],
    ]);

    it('verifies with the algorithm check on', function () {
        config(['hashing.driver' => 'argon2id', 'hashing.bcrypt.verify' => true, 'hashing.argon.verify' => true]);
        app('hash')->forgetDrivers();
        $credential = password(password_hash('correct horse', PASSWORD_BCRYPT));

        $proof = (new PasswordType)->verify(Surface::SIGN_IN, ['password' => 'correct horse'], [$credential]);

        expect($proof->proven)->toBeTrue();
    });

    it('rehashes a hash whose cost is out of date', function () {
        $credential = password(password_hash('correct horse', PASSWORD_BCRYPT, ['cost' => 5]));
        config(['hashing.driver' => 'bcrypt', 'hashing.bcrypt.rounds' => 4]);

        $proof = (new PasswordType)->verify(Surface::SIGN_IN, ['password' => 'correct horse'], [$credential]);

        expect(password_get_info((string) $proof->updatedSecret)['options'])->toBe(['cost' => 4]);
    });

    it('keeps a current hash as it is', function () {
        $credential = password(Hash::make('correct horse'));

        expect((new PasswordType)->verify(Surface::SIGN_IN, ['password' => 'correct horse'], [$credential])->updatedSecret)->toBeNull();
    });

    it('verifies an imported bcrypt hash of a password longer than 72 bytes against its first 72', function () {
        config(['hashing.driver' => 'bcrypt']);
        $long = str_repeat('a', 72);
        $credential = password(password_hash($long.'-imported', PASSWORD_BCRYPT, ['cost' => 4]));

        expect((new PasswordType)->verify(Surface::SIGN_IN, ['password' => $long.'-typed'], [$credential])->proven)->toBeTrue();
    });

    it('rejects a hash of an unknown format', function () {
        $credential = password('not a hash');

        expect((new PasswordType)->verify(Surface::SIGN_IN, ['password' => 'not a hash'], [$credential]))
            ->toEqual(Proof::rejected('password.mismatch', $credential));
    });

    it('rejects when the subject holds no password', function () {
        expect((new PasswordType)->verify(Surface::SIGN_IN, ['password' => 'correct horse'], []))
            ->toEqual(Proof::rejected('password.mismatch'));
    });

    it('checks a password against the dummy hash when the subject holds none, as it would a real one', function (array $credentials) {
        $hash = spyOnHash();
        $hash->shouldReceive('check')->once()->passthru();

        (new PasswordType)->verify(Surface::SIGN_IN, ['password' => 'correct horse'], $credentials);
    })->with([
        'no password' => [[]],
        'an unknown format' => [[password('not a hash')]],
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

    it('refuses to verify on a surface it has no flow for yet', function (Surface $surface) {
        (new PasswordType)->verify($surface, ['password' => Str::random()], []);
    })->with([Surface::REGISTRATION, Surface::ENROLLMENT])->throws(LogicException::class);
});
