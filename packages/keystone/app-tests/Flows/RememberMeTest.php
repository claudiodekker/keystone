<?php

use ClaudioDekker\Keystone\Jobs\EndSessions;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use Illuminate\Support\Facades\Auth;

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
