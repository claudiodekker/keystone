<?php

use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;

beforeEach(function () {
    $this->countLimitsIn(Cache::repository(new class extends ArrayStore
    {
        public function get($key)
        {
            throw new RuntimeException('The limiter store is down.');
        }

        public function put($key, $value, $seconds)
        {
            throw new RuntimeException('The limiter store is down.');
        }

        public function add($key, $value, $seconds)
        {
            throw new RuntimeException('The limiter store is down.');
        }

        public function increment($key, $value = 1)
        {
            throw new RuntimeException('The limiter store is down.');
        }

        public function decrement($key, $value = 1)
        {
            throw new RuntimeException('The limiter store is down.');
        }
    }));
});

it('refuses a sign-in as throttled while the limiter store is down, and reports the failure', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignInThrottled($response);
    expect($this->securityEvents()->for($user))->toBeEmpty();
    Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'The limiter store is down.');
});

it('still answers a page view while the limiter store is down, and reports the failure', function () {
    $response = $this->get(route('login'));

    $this->assertSignInPage($response);
    Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'The limiter store is down.');
});
