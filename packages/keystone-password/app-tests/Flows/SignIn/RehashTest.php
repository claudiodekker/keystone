<?php

use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Password\AppTests\PasswordSupport;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Exceptions;

beforeEach(function () {
    config(['hashing.driver' => 'argon2id']);

    $this->user = $this->createUser();
    $this->addVerifiedAddress($this->user, 'ada@example.com');
    $this->support = (new PasswordSupport)->arrangePassword($this->user, PasswordSupport::PASSWORD, 'bcrypt');
    $this->fields = ['identifier' => 'ada@example.com', ...$this->support->validProof(Surface::SIGN_IN, $this->user)];
});

it('keeps the hash when rehash_on_login is off', function () {
    config(['hashing.rehash_on_login' => false]);

    $first = $this->post(route('login.submit', 'password'), $this->fields);

    $this->support->refuseHashesOf('bcrypt');
    Auth::forgetGuards();
    $second = $this->flushSession()->post(route('login.submit', 'password'), $this->fields);

    $this->assertSignedIn($first, '/');
    $this->assertSignInRefused($second, __('keystone::messages.credentials-rejected'));
});

it('rehashes when the hashing config leaves rehash_on_login out', function () {
    config(['hashing' => Arr::except(config('hashing'), 'rehash_on_login')]);

    $first = $this->post(route('login.submit', 'password'), $this->fields);

    $this->support->refuseHashesOf('bcrypt');
    Auth::forgetGuards();
    $second = $this->flushSession()->post(route('login.submit', 'password'), $this->fields);

    Auth::forgetGuards();
    $next = $this->get(route('login'));

    $this->assertSignedIn($first, '/');
    $this->assertSignedIn($second, '/');
    $this->assertAlreadySignedIn($next);
});

it('signs in and keeps the hash when the hasher fails, reporting the failure', function () {
    $this->support->failHashingWith('argon2id');

    $first = $this->post(route('login.submit', 'password'), $this->fields);

    $this->support->refuseHashesOf('bcrypt');
    Auth::forgetGuards();
    $second = $this->flushSession()->post(route('login.submit', 'password'), $this->fields);

    $this->assertSignedIn($first, '/');
    $this->assertSignInRefused($second, __('keystone::messages.credentials-rejected'));
    Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'The hasher failed to hash.');
});
