<?php

use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Password\AppTests\PasswordSupport;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

it('gives the contract AppTests the test support of the installed password type', function () {
    $names = collect($this->supportsFor(Surface::SIGN_IN))
        ->map(fn ($support) => $support->type()->name())
        ->all();

    expect($names)->toContain('password');
});

it('arranges a password hashed by the named driver', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    (new PasswordSupport)->arrangePassword($user, '  odd horse  ', 'argon');

    $response = $this->post(route('login.submit', 'password'), ['identifier' => 'ada@example.com', 'password' => '  odd horse  ']);

    Auth::forgetGuards();
    $next = $this->get(route('login'));

    $this->assertSignedIn($response, '/');
    $this->assertAlreadySignedIn($next);
});

it('makes a driver refuse every hash', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = (new PasswordSupport)->arrangeCredential(Surface::SIGN_IN, $user);

    $support->refuseHashesOf(config('hashing.driver'));
    $response = $this->post(route('login.submit', 'password'), ['identifier' => 'ada@example.com', ...$support->validProof(Surface::SIGN_IN, $user)]);

    $this->assertSignInRefused($response, __('keystone::messages.credentials-rejected'));
});

it('arranges a hash as an import stores it', function (string $hash) {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    (new PasswordSupport)->arrangeHash($user, $hash);

    $response = $this->post(route('login.submit', 'password'), ['identifier' => 'ada@example.com', 'password' => 'imported horse']);

    Auth::forgetGuards();
    $next = $this->get(route('login'));

    $this->assertSignedIn($response, '/');
    $this->assertAlreadySignedIn($next);
})->with([
    'bcrypt' => '$2y$04$wCQceMf4BJPg4Prg5iiqJOZZaj2Sh6n/0Lhb6dt3yX./LogEWNzv6',
    'argon2i' => '$argon2i$v=19$m=64,t=1,p=1$IrW0nsDpJZrqdbYmMziCzg$YSqcH2r6IgQX0q4MznxMzeFZUddAYo45B54bxeAC+k8',
    'argon2id' => '$argon2id$v=19$m=64,t=1,p=1$7p5AJIiGU/s4B8u3C0BzRQ$dp62vgHDAzSqqWqMbm2PNYOIA2p3iVYazdlUPeaDSg8',
]);

it('makes a driver fail to hash while it still checks', function () {
    $hash = Hash::make('odd horse');

    (new PasswordSupport)->failHashingWith('bcrypt');

    expect(Hash::check('odd horse', $hash))->toBeTrue()
        ->and(fn () => Hash::make('odd horse'))->toThrow(RuntimeException::class, 'The hasher failed to hash.');
});
