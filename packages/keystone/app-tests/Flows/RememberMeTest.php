<?php

use ClaudioDekker\Keystone\AppTests\Support\Browser;
use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Jobs\EndSessions;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Models\UserRememberToken;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->withoutEnrollmentMandates();
});

it('signs a browser that ticked remember me back in once its session is gone', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $signedIn = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        'remember' => true,
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->keepCookiesFrom($signedIn);
    $this->switchBackTo($this->browserWithCopiedCookies());

    $response = $this->get(route('login'));

    $this->assertAlreadySignedIn($response);
});

it('leaves a browser that did not tick remember me signed out once its session is gone', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $signedIn = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->keepCookiesFrom($signedIn);
    $this->switchBackTo($this->browserWithCopiedCookies());

    $response = $this->get(route('login'));

    $this->assertSignInPage($response, ['status' => null]);
});

it('restores nothing once the remember-me lifetime has passed since the sign-in, however often the cookie was used', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);
    $lifetime = $this->rememberLifetimeSeconds();

    $signedIn = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        'remember' => true,
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->keepCookiesFrom($signedIn);
    $remembered = $this->browserWithCopiedCookies();

    $this->switchBackTo($remembered);
    $this->travel($lifetime - 1)->seconds();
    $beforeTheEnd = $this->get(route('login'));

    $this->switchBackTo($remembered);
    $this->travel(1)->second();
    $atTheEnd = $this->get(route('login'));

    $this->assertAlreadySignedIn($beforeTheEnd);
    $this->assertSignInPage($atTheEnd, ['status' => null]);
});

it("restores nothing once the account's credential epoch moved since the sign-in", function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $signedIn = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        'remember' => true,
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->keepCookiesFrom($signedIn);
    $this->switchBackTo($this->browserWithCopiedCookies());
    EndSessions::dispatchSync($user, operator: 'margaret');

    $response = $this->get(route('login'));

    $this->assertSignInPage($response, ['status' => null]);
});

it("restores nothing for an account suspended on the credential epoch its cookie was issued on", function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $signedIn = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        'remember' => true,
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->keepCookiesFrom($signedIn);
    $this->switchBackTo($this->browserWithCopiedCookies());
    $user->newQuery()->whereKey($user->getAuthIdentifier())->update(['suspended_at' => now()]);

    $response = $this->get(route('login'));

    $this->assertSignInPage($response, ['status' => null]);
});

it('forgets only the remember-me cookie of the browser that signs out', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $atHome = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        'remember' => true,
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->keepCookiesFrom($atHome);
    $home = $this->browserWithCopiedCookies();
    $this->switchToFreshBrowser();

    $atWork = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        'remember' => true,
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->keepCookiesFrom($atWork);
    $work = $this->browserWithCopiedCookies();
    $this->post(route('logout'));

    $this->switchBackTo($work);
    $signedOut = $this->get(route('login'));

    $this->switchBackTo($home);
    $remembered = $this->get(route('login'));

    $this->assertSignInPage($signedOut, ['status' => null]);
    $this->assertAlreadySignedIn($remembered);
});

it('restores a session past its absolute lifetime silently while the remember-me cookie is live', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $signedIn = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        'remember' => true,
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->keepCookiesFrom($signedIn);
    Auth::forgetGuards();
    $this->travel($this->absoluteLifetimeSeconds())->seconds();

    $response = $this->get(route('login'));

    $this->assertAlreadySignedIn($response);
    $this->assertSiteDataKept($response);
    expect($this->securityEvents()->for($user)->where('type', SecurityEventType::SESSION_ENDED))->toBeEmpty();
});

it('refuses a return whose account newly owes enrollment, and sends the user to sign in again', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $signedIn = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        'remember' => true,
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->keepCookiesFrom($signedIn);
    $this->switchBackTo($this->browserWithCopiedCookies());
    config(['keystone.require_second_factor' => true]);

    $response = $this->get(route('login'));

    $this->assertSignInPage($response, ['status' => __('keystone::messages.enrollment-owed')]);
    expect($this->securityEvents()->for($user)->where('type', SecurityEventType::REQUEST_REJECTED)->pluck('reason')->all())->toBe(['keystone.enrollment_owed']);
});

it('forgets the remember-me token of a return refused because its account owed enrollment', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $signedIn = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        'remember' => true,
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->keepCookiesFrom($signedIn);
    $remembered = $this->browserWithCopiedCookies();
    $this->switchBackTo($remembered);
    config(['keystone.require_second_factor' => true]);
    $this->get(route('login'));

    config(['keystone.require_second_factor' => false]);
    $this->switchBackTo($remembered);

    $response = $this->get(route('login'));

    $this->assertSignInPage($response, ['status' => null]);
});

it('deletes a dead remember-me cookie and its token when the browser presents it, and records the refusal', function (Closure $kill) {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $signedIn = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        'remember' => true,
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->keepCookiesFrom($signedIn);
    $this->switchBackTo($this->browserWithCopiedCookies());
    $kill($this, $user);

    $response = $this->get(route('login'));

    $this->assertSignInPage($response, ['status' => null]);
    expect($response->getCookie('__Host-keystone_remember', decrypt: false)?->isCleared())->toBeTrue()
        ->and(UserRememberToken::query()->where('user_id', $user->getAuthIdentifier())->exists())->toBeFalse()
        ->and($this->securityEvents()->for($user)->where('type', SecurityEventType::REQUEST_REJECTED)->pluck('reason')->all())->toBe(['keystone.dead_remember_cookie']);
})->with([
    'past its lifetime' => [fn ($test) => $test->travel($test->rememberLifetimeSeconds())->seconds()],
    'issued before an epoch move' => [fn ($test, KeystoneUser $user) => EndSessions::dispatchSync($user, operator: 'margaret')],
    'of a suspended account' => [fn ($test, KeystoneUser $user) => $user->newQuery()->whereKey($user->getAuthIdentifier())->update(['suspended_at' => now()])],
]);

it('deletes a remember-me cookie no token matches, recording the refusal against nobody', function () {
    $this->withUnencryptedCookie('__Host-keystone_remember', str_repeat('5e', 32));

    $response = $this->get(route('login'));

    $lines = collect($this->securityEvents()->logged())->where('type', 'request.rejected')->values();

    $this->assertSignInPage($response, ['status' => null]);
    expect($response->getCookie('__Host-keystone_remember', decrypt: false)?->isCleared())->toBeTrue()
        ->and($lines)->toHaveCount(1)
        ->and($lines[0]['reason'])->toBe('keystone.dead_remember_cookie')
        ->and($lines[0]['user_id'])->toBeNull();
});

it('records a remembered return as signed_in with the reason remembered, from the device the account knows', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $signedIn = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        'remember' => true,
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->keepCookiesFrom($signedIn);
    $this->switchBackTo($this->browserWithCopiedCookies());

    $this->get(route('login'));

    $signIns = $this->securityEvents()->for($user)->where('type', SecurityEventType::SIGNED_IN);

    expect($signIns->pluck('reason')->all())->toBe([null, 'keystone.remembered'])
        ->and($signIns->pluck('known_device')->all())->toBe([false, true]);
});

it('alerts the owner when a copy of the remember-me cookie restores in a browser the account does not know', function () {
    Notification::fake();
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $signedIn = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        'remember' => true,
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $remember = $signedIn->getCookie('__Host-keystone_remember', decrypt: false)->getValue();
    $this->switchBackTo(new Browser([], ['__Host-keystone_remember' => $remember]));

    $response = $this->get(route('login'));

    $this->assertAlreadySignedIn($response);
    expect($this->sentAlerts()->pluck('alert.type')->all())->toBe([SecurityEventType::SIGNED_IN, SecurityEventType::SIGNED_IN])
        ->and($this->securityEvents()->for($user)->where('type', SecurityEventType::SIGNED_IN)->pluck('known_device')->all())->toBe([false, false]);
});

it('signs every tab of a reopened browser back in at once, with no alert, since a return re-keys neither cookie', function () {
    Notification::fake();
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $signedIn = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        'remember' => true,
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->keepCookiesFrom($signedIn);
    $reopened = $this->browserWithCopiedCookies();

    $this->switchBackTo($reopened);
    $firstTab = $this->get(route('login'));

    $this->switchBackTo($reopened);
    $secondTab = $this->get(route('login'));

    $this->assertAlreadySignedIn($firstTab);
    $this->assertAlreadySignedIn($secondTab);
    expect($this->sentAlerts()->pluck('alert.type')->all())->toBe([SecurityEventType::SIGNED_IN]);
});

it("tells the app a request was signed in through remember me, as Laravel's Login event and Auth::viaRemember() do", function () {
    Route::middleware('web')->get('/keystone/app-tests/via-remember', fn () => [
        'user' => Auth::id(),
        'via_remember' => Auth::viaRemember(),
    ]);

    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $signedIn = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        'remember' => true,
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->keepCookiesFrom($signedIn);
    $this->switchBackTo($this->browserWithCopiedCookies());
    Event::fake([Login::class]);

    $response = $this->get('/keystone/app-tests/via-remember');

    Event::assertDispatchedTimes(Login::class, 1);
    Event::assertDispatched(Login::class, fn (Login $login) => $login->remember && $login->user->is($user));
    expect($response->json())->toBe(['user' => $user->getAuthIdentifier(), 'via_remember' => true]);
});

it('remembers a browser that ticked remember me once it answers the challenge', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $firstFactor = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);
    $secondFactor = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);

    $this->post(route('login.submit', $firstFactor->type()->name()), [
        'identifier' => 'ada@example.com',
        'remember' => true,
        ...$firstFactor->validProof(Surface::SIGN_IN, $user),
    ]);

    $signedIn = $this->answerChallenge($user, $secondFactor);

    $this->keepCookiesFrom($signedIn);
    $this->switchBackTo($this->browserWithCopiedCookies());

    $response = $this->get(route('login'));

    $this->assertAlreadySignedIn($response);
});

it('remembers a browser that ticked remember me once it saves the recovery codes its account owed', function () {
    config(['keystone.require_recovery_codes' => true]);
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $firstFactor = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);
    $secondFactor = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);

    $this->post(route('login.submit', $firstFactor->type()->name()), [
        'identifier' => 'ada@example.com',
        'remember' => true,
        ...$firstFactor->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->answerChallenge($user, $secondFactor);
    $codes = $this->shownRecoveryCodes($this->get(route('login.enrollment')));
    $signedIn = $this->post(route('login.enrollment.submit', 'recovery-code'), ['recovery_code' => $codes[3]]);

    $this->keepCookiesFrom($signedIn);
    $this->switchBackTo($this->browserWithCopiedCookies());

    $response = $this->get(route('login'));

    $this->assertAlreadySignedIn($response);
});

it('ignores a ticked box while the remember-me lifetime is zero', function () {
    config(['keystone.remember.lifetime_seconds' => 0]);
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $signedIn = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        'remember' => true,
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignedIn($signedIn, '/');
    expect($signedIn->getCookie('__Host-keystone_remember', decrypt: false))->toBeNull()
        ->and(UserRememberToken::query()->exists())->toBeFalse();
});

it('ignores a cookie issued earlier while the remember-me lifetime is zero, and restores from it again once turned back on', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $signedIn = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        'remember' => true,
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->keepCookiesFrom($signedIn);
    $remembered = $this->browserWithCopiedCookies();
    $lifetime = $this->rememberLifetimeSeconds();

    config(['keystone.remember.lifetime_seconds' => 0]);
    $this->switchBackTo($remembered);
    $whileOff = $this->get(route('login'));

    config(['keystone.remember.lifetime_seconds' => $lifetime]);
    $this->switchBackTo($remembered);
    $onceBackOn = $this->get(route('login'));

    $this->assertSignInPage($whileOff, ['status' => null]);
    $this->assertAlreadySignedIn($onceBackOn);
    expect($this->securityEvents()->for($user)->where('type', SecurityEventType::REQUEST_REJECTED))->toBeEmpty();
});
