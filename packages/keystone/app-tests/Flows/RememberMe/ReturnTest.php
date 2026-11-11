<?php

use ClaudioDekker\Keystone\AppTests\Support\Browser;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->withoutEnrollmentMandates();
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

it('restores once the session has started, so a global middleware asking earlier sees a guest', function () {
    $askedEarly = [];
    $this->app->make(Kernel::class)->pushMiddleware(function (Request $request, Closure $next) use (&$askedEarly) {
        $askedEarly[] = Auth::id();

        return $next($request);
    });

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
    expect($askedEarly)->toBe([null, null])
        ->and($this->securityEvents()->for($user)->where('type', SecurityEventType::SIGNED_IN))->toHaveCount(2);
});
