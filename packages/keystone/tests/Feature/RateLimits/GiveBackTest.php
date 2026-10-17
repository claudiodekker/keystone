<?php

// Fault injection through the fake type: no installed type throws on demand or holds a request open, so no AppTest can.

use ClaudioDekker\Keystone\Exceptions\ThrottledException;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\InitiateShape;
use ClaudioDekker\Keystone\Methods\Proof;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Methods\Verification;
use ClaudioDekker\Keystone\Models\UserCredential;
use ClaudioDekker\Keystone\Models\UserEmail;
use ClaudioDekker\Keystone\SignIn\Refused;
use ClaudioDekker\Keystone\SignIn\SignIn;
use ClaudioDekker\Keystone\Tests\Fixtures\Models\User;

it('gives the attempt back when the type throws', function () {
    $type = new class implements CredentialType
    {
        public function name(): string
        {
            return 'throwing';
        }

        public function initiateShape(Surface $surface): ?InitiateShape
        {
            return InitiateShape::FORM;
        }

        public function isMultiFactorOnItsOwn(): bool
        {
            return false;
        }

        public function rules(Surface $surface): array
        {
            return [];
        }

        public function verify(Verification $verification): Proof
        {
            throw new RuntimeException('The type is down.');
        }
    };

    $attempt = fn () => $this->app->make(SignIn::class)->attempt($type, ['identifier' => 'ada@example.com']);

    foreach (range(1, config('keystone.rate_limits.failed_attempts_per_hour') + 5) as $i) {
        expect($attempt)->toThrow(RuntimeException::class, 'The type is down.');
    }

    expect($attempt)->toThrow(RuntimeException::class, 'The type is down.');
});

it('keeps an accepted proof that core refuses counted', function () {
    $ada = User::factory()->create();
    $grace = User::factory()->create();
    UserEmail::factory()->for($ada, 'user')->verified()->create(['address' => 'ada@example.com']);
    $graces = UserCredential::factory()->for($grace, 'user')->create(['type' => 'form', 'secret' => 'grace horse']);
    $type = Keystone::credentialTypes()->find('form', Surface::SIGN_IN);
    $type->forgeProofOf($graces);
    $attempt = fn () => $this->app->make(SignIn::class)->attempt($type, [
        'identifier' => 'ada@example.com',
        'secret' => 'grace horse',
    ]);

    foreach (range(1, config('keystone.rate_limits.failed_attempts_per_hour')) as $i) {
        expect($attempt())->toBeInstanceOf(Refused::class);
    }

    expect($attempt)->toThrow(ThrottledException::class);
});

it('gives nothing back once the window it was counted in has ended', function () {
    $ada = User::factory()->create();
    UserEmail::factory()->for($ada, 'user')->verified()->create(['address' => 'ada@example.com']);
    UserCredential::factory()->for($ada, 'user')->create(['type' => 'form', 'secret' => 'correct horse']);
    $type = Keystone::credentialTypes()->find('form', Surface::SIGN_IN);
    $wrong = fn () => $this->app->make(SignIn::class)->attempt($type, [
        'identifier' => 'ada@example.com',
        'secret' => 'wrong horse',
    ]);

    $type->duringNextVerify(fn () => $this->travel(60 * 60 + 1)->seconds());
    $this->app->make(SignIn::class)->attempt($type, [
        'identifier' => 'ada@example.com',
        'secret' => 'correct horse',
    ]);

    foreach (range(1, config('keystone.rate_limits.failed_attempts_per_hour')) as $i) {
        expect($wrong())->toBeInstanceOf(Refused::class);
    }

    expect($wrong)->toThrow(ThrottledException::class);
});
