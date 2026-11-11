<?php

// Session drivers: AppTests run on the app's own session driver, so only core can run one flow on each.

use ClaudioDekker\Keystone\Models\UserCredential;
use ClaudioDekker\Keystone\Models\UserEmail;
use ClaudioDekker\Keystone\Tests\Fixtures\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config(['keystone.require_recovery_codes' => false, 'keystone.require_second_factor' => false]);
});

it("signs out another session on its next request once the operator ends the account's sessions", function (string $driver) {
    config(['session.driver' => $driver]);
    $ada = User::factory()->create();
    UserEmail::factory()->for($ada, 'user')->verified()->create(['address' => 'ada@example.com']);
    UserCredential::factory()->for($ada, 'user')->create(['type' => 'form', 'secret' => 'correct horse']);
    $cookiesOf = fn (TestResponse $response) => collect($response->headers->getCookies())
        ->mapWithKeys(fn ($cookie) => [$cookie->getName() => $cookie->getValue()])
        ->all();

    $nextRequest = function (array $cookies) {
        Auth::forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');

        return $this->withUnencryptedCookies($cookies)->get(route('login'));
    };

    $signedIn = $this->post(route('login.submit', 'form'), ['identifier' => 'ada@example.com', 'secret' => 'correct horse']);
    $before = $nextRequest($cookiesOf($signedIn));

    Artisan::call('keystone:end-sessions', ['user' => $ada->getKey(), '--operator' => 'margaret']);

    $after = $nextRequest($cookiesOf($before));

    $before->assertRedirect('/');
    $after->assertOk();
})->with(['cookie', 'file', 'redis']);
