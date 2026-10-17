<?php

use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Password\AppTests\PasswordSupport;
use Illuminate\Support\Facades\Auth;

it('signs in with a stored hash of another driver, and rehashes it to the app driver', function (string $stored, string $app) {
    config(['hashing.driver' => $app, 'hashing.bcrypt.verify' => true, 'hashing.argon.verify' => true]);
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = (new PasswordSupport)->arrangePassword($user, PasswordSupport::PASSWORD, $stored);
    $fields = ['identifier' => 'ada@example.com', ...$support->validProof(Surface::SIGN_IN, $user)];

    $first = $this->post(route('login.submit', 'password'), $fields);

    $support->refuseHashesOf($stored);
    Auth::forgetGuards();
    $second = $this->flushSession()->post(route('login.submit', 'password'), $fields);

    $this->assertSignedIn($first, '/');
    $this->assertSignedIn($second, '/');
})->with([
    'bcrypt under argon2id' => ['bcrypt', 'argon2id'],
    'argon2i under argon2id' => ['argon', 'argon2id'],
    'argon2id under bcrypt' => ['argon2id', 'bcrypt'],
]);
