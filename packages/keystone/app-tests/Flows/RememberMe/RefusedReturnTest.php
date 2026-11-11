<?php

use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Jobs\EndSessions;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Models\UserRememberToken;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;

beforeEach(function () {
    $this->withoutEnrollmentMandates();
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

it('restores nothing for an account suspended on the credential epoch its cookie was issued on', function () {
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
