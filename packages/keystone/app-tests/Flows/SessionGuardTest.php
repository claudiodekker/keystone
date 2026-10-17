<?php

use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Keystone;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

beforeEach(function () {
    Event::fake([OtherDeviceLogout::class]);
});

it('signs nobody in through a SessionGuard method, even with a hash in users.password', function (Closure $signIn) {
    $user = $this->createUser(['email' => 'ada@example.com', 'password' => Hash::make('legacy-secret')]);

    $this->duringRequest(fn () => $signIn($user));

    $next = $this->get(route('login'));

    $this->assertSignInPage($next);
})->with([
    'attempt' => fn (KeystoneUser $user) => Auth::attempt(['email' => 'ada@example.com', 'password' => 'legacy-secret']),
    'attemptWhen' => fn (KeystoneUser $user) => Auth::attemptWhen(['email' => 'ada@example.com', 'password' => 'legacy-secret']),
    'loginUsingId' => fn (KeystoneUser $user) => Auth::loginUsingId($user->getAuthIdentifier()),
    'once' => fn (KeystoneUser $user) => Auth::once(['email' => 'ada@example.com', 'password' => 'legacy-secret']),
    'onceUsingId' => fn (KeystoneUser $user) => Auth::onceUsingId($user->getAuthIdentifier()),
    'validate' => fn (KeystoneUser $user) => Auth::validate(['email' => 'ada@example.com', 'password' => 'legacy-secret']),
]);

it('refuses HTTP Basic credentials, even with a hash in users.password', function (Closure $signIn) {
    $this->createUser(['email' => 'ada@example.com', 'password' => Hash::make('legacy-secret')]);

    $basic = fn () => $this->duringRequest($signIn);

    expect($basic)->toThrow(UnauthorizedHttpException::class);
})->with([
    'basic' => fn () => Auth::setRequest(Request::create('/', server: ['PHP_AUTH_USER' => 'ada@example.com', 'PHP_AUTH_PW' => 'legacy-secret']))->basic(),
    'onceBasic' => fn () => Auth::setRequest(Request::create('/', server: ['PHP_AUTH_USER' => 'ada@example.com', 'PHP_AUTH_PW' => 'legacy-secret']))->onceBasic(),
]);

it('throws from Auth::login(), which has no way to report a refusal', function () {
    $user = $this->createUser();

    $login = fn () => $this->duringRequest(fn () => Auth::login($user));

    expect($login)->toThrow(LogicException::class, 'Keystone signs users in. Auth::login() cannot report a refusal.');
});

it('signs no other device out through Auth::logoutOtherDevices()', function () {
    $user = $this->createUser(['email' => 'ada@example.com', 'password' => Hash::make('legacy-secret')]);

    $this->actingAs($user)->duringRequest(fn () => Auth::logoutOtherDevices('legacy-secret'));

    Event::assertNotDispatched(OtherDeviceLogout::class);
});

it("restores no session from Laravel's remember-me cookie", function () {
    $user = $this->createUser(['email' => 'ada@example.com', 'password' => Hash::make('legacy-secret'), 'remember_token' => 'legacy-token']);
    $guard = Keystone::guard();
    $this->withCookie($guard->getRecallerName(), $user->getAuthIdentifier().'|legacy-token|'.$guard->hashPasswordForCookie($user->getAuthPassword()));

    $response = $this->get(route('login'));

    $this->assertSignInPage($response);
});

it('still lets a test sign a user in with actingAs()', function () {
    $user = $this->createUser();

    $response = $this->actingAs($user)->get(route('login'));

    $this->assertAlreadySignedIn($response);
});
