<?php

// Fault injection through a fake type: no installed type answers with a short code on the sign-in surface, so no AppTest can reach the daily ceiling there.

use ClaudioDekker\Keystone\Exceptions\ThrottledException;
use ClaudioDekker\Keystone\Models\UserCredential;
use ClaudioDekker\Keystone\Models\UserEmail;
use ClaudioDekker\Keystone\SignIn\SignedIn;
use ClaudioDekker\Keystone\SignIn\SignIn;
use ClaudioDekker\Keystone\Tests\Fixtures\Methods\FakeCodeType;
use ClaudioDekker\Keystone\Tests\Fixtures\Models\User;

it('refuses the 101st failed short code in a day, with the hourly allowance left', function () {
    $ada = User::factory()->create();
    UserEmail::factory()->for($ada, 'user')->verified()->create(['address' => 'ada@example.com']);
    UserCredential::factory()->for($ada, 'user')->create(['type' => 'code', 'secret' => '613302']);
    $attempt = fn () => $this->app->make(SignIn::class)->attempt(new FakeCodeType, ['identifier' => 'ada@example.com', 'secret' => '000000']);

    foreach (range(1, 5) as $hour) {
        foreach (range(1, 20) as $i) {
            $attempt();
        }

        $this->travel(61)->minutes();
    }

    expect($attempt)->toThrow(ThrottledException::class);
});

it('counts only the short codes the hourly allowance let through toward the daily ceiling', function () {
    $ada = User::factory()->create();
    UserEmail::factory()->for($ada, 'user')->verified()->create(['address' => 'ada@example.com']);
    UserCredential::factory()->for($ada, 'user')->create(['type' => 'code', 'secret' => '613302']);
    $type = new FakeCodeType;
    $attempt = fn () => $this->app->make(SignIn::class)->attempt($type, ['identifier' => 'ada@example.com', 'secret' => '000000']);

    foreach (range(1, 130) as $i) {
        rescue($attempt, report: false);
    }

    $this->travel(61)->minutes();

    foreach (range(1, 20) as $i) {
        $attempt();
    }

    expect($type->comparisons())->toBe(40);
});

it('gives a short code that signs in back to the daily ceiling too', function () {
    $ada = User::factory()->create();
    UserEmail::factory()->for($ada, 'user')->verified()->create(['address' => 'ada@example.com']);
    UserCredential::factory()->for($ada, 'user')->create(['type' => 'code', 'secret' => '613302']);
    $attempt = fn () => $this->app->make(SignIn::class)->attempt(new FakeCodeType, ['identifier' => 'ada@example.com', 'secret' => '613302']);

    foreach (range(1, 100) as $i) {
        $attempt();
    }

    expect($attempt())->toBeInstanceOf(SignedIn::class);
});
