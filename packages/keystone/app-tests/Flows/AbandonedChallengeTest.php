<?php

use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Jobs\EndSessions;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->withoutEnrollmentMandates();

    Notification::fake();
});

it('alerts the owner once a browser the account does not know has left the challenge for 7 minutes', function () {
    $user = $this->createUser();
    $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.23'])->passFirstFactor($user);

    $this->travel(7)->minutes();
    $this->artisan('keystone:alert-abandoned-challenges');

    $abandoned = $this->securityEvents()->for($user)->where('type', SecurityEventType::CHALLENGE_ABANDONED);

    expect($this->sentAlerts()->pluck('alert.type')->all())->toBe([SecurityEventType::CHALLENGE_ABANDONED])
        ->and($abandoned->pluck('ip_address')->all())->toBe(['198.51.100.23']);
});

it('sends no alert once the challenge was passed', function () {
    config(['keystone.notifications.signed_in' => null]);
    $user = $this->createUser();
    $support = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);
    $this->answerChallenge($user, $support);

    $this->travel(7)->minutes();
    $this->artisan('keystone:alert-abandoned-challenges');

    expect($this->sentAlerts())->toBeEmpty()
        ->and($this->securityEvents()->for($user)->where('type', SecurityEventType::CHALLENGE_ABANDONED))->toBeEmpty();
});

it('still alerts the owner once the pending sign-in was dropped without passing the challenge', function (Closure $drop) {
    $user = $this->createUser();
    $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);
    $drop($this, $user);

    $this->travel(15)->minutes();
    $this->artisan('keystone:alert-abandoned-challenges');

    expect($this->sentAlerts()->where('alert.type', SecurityEventType::CHALLENGE_ABANDONED))->toHaveCount(1);
})->with([
    'cancelled' => [fn ($test) => $test->delete(route('login.challenge.cancel'))],
    'expired' => [function ($test) {
        $test->travel(15)->minutes();
        $test->get(route('login.challenge'));
    }],
    'voided by an epoch move' => [function ($test, KeystoneUser $user) {
        EndSessions::dispatchSync($user, operator: 'margaret');
        $test->get(route('login.challenge'));
    }],
]);

it('sends no alert about a challenge left by a browser the account knows', function () {
    $user = $this->createUser();
    $support = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);
    $this->keepCookiesFrom($this->answerChallenge($user, $support));
    $this->post(route('logout'));
    $this->passFirstFactor($user);

    $this->travel(7)->minutes();
    $this->artisan('keystone:alert-abandoned-challenges');

    expect($this->sentAlerts()->where('alert.type', SecurityEventType::CHALLENGE_ABANDONED))->toBeEmpty();
});

it("alerts the owner about a challenge left by a copy of the device cookie that the owner's browser has since replaced", function () {
    $user = $this->createUser();
    $support = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);
    $this->keepCookiesFrom($this->answerChallenge($user, $support));
    $copy = $this->browserWithCopiedCookies();
    $this->post(route('logout'));
    $this->travel(5)->minutes();
    $this->passFirstFactor($user);
    $this->keepCookiesFrom($this->answerChallenge($user, $support));
    $this->switchBackTo($copy);
    $this->passFirstFactor($user);

    $this->travel(7)->minutes();
    $this->artisan('keystone:alert-abandoned-challenges');

    expect($this->sentAlerts()->where('alert.type', SecurityEventType::CHALLENGE_ABANDONED))->not->toBeEmpty();
});

it('still alerts the owner about a challenge left by one browser once another browser passed its own', function () {
    config(['keystone.notifications.signed_in' => null]);
    $user = $this->createUser();
    $support = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.23'])->passFirstFactor($user);
    $this->switchToFreshBrowser();
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->passFirstFactor($user);
    $this->answerChallenge($user, $support);

    $this->travel(7)->minutes();
    $this->artisan('keystone:alert-abandoned-challenges');

    $abandoned = $this->securityEvents()->for($user)->where('type', SecurityEventType::CHALLENGE_ABANDONED);

    expect($this->sentAlerts()->pluck('alert.type')->unique()->all())->toBe([SecurityEventType::CHALLENGE_ABANDONED])
        ->and($abandoned->pluck('ip_address')->all())->toBe(['198.51.100.23']);
});

it('still alerts the owner about a challenge left in a browser where another account, which knows the browser, then passed its own', function () {
    config(['keystone.notifications.signed_in' => null]);
    $ada = $this->createUser();
    $grace = $this->createUser();
    $support = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $ada);
    $support->arrangeCredential(Surface::CHALLENGE, $grace);
    $this->passFirstFactor($grace);
    $this->keepCookiesFrom($this->answerChallenge($grace, $support));
    $this->post(route('logout'));
    $this->travel(5)->minutes();
    $this->passFirstFactor($ada);
    $this->passFirstFactor($grace);
    $this->answerChallenge($grace, $support);

    $this->travel(7)->minutes();
    $this->artisan('keystone:alert-abandoned-challenges');

    expect($this->securityEvents()->for($ada)->where('type', SecurityEventType::CHALLENGE_ABANDONED))->toHaveCount(1);
});

it('keeps one pending challenge, at the time of the first hold, when the browser passes the first factor again', function () {
    $user = $this->createUser();
    $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);
    $heldAt = now()->toDateTimeString();
    $this->travel(4)->minutes();
    $this->delete(route('login.challenge.cancel'));
    $this->passFirstFactor($user);

    $this->travel(10)->minutes();
    $this->artisan('keystone:alert-abandoned-challenges');

    $abandoned = $this->securityEvents()->for($user)->where('type', SecurityEventType::CHALLENGE_ABANDONED);

    expect($abandoned->map(fn ($event) => $event->occurred_at->toDateTimeString())->values()->all())->toBe([$heldAt]);
});

it('alerts the owners of two accounts whose sign-ins one browser held in turn and left', function () {
    $ada = $this->createUser();
    $grace = $this->createUser();
    $support = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $ada);
    $support->arrangeCredential(Surface::CHALLENGE, $grace);
    $this->passFirstFactor($ada);
    $this->passFirstFactor($grace);

    $this->travel(7)->minutes();
    $this->artisan('keystone:alert-abandoned-challenges');

    expect($this->securityEvents()->for($ada)->where('type', SecurityEventType::CHALLENGE_ABANDONED))->toHaveCount(1)
        ->and($this->securityEvents()->for($grace)->where('type', SecurityEventType::CHALLENGE_ABANDONED))->toHaveCount(1);
});

it('sends no alert about a sign-in held at enrollment rather than at the challenge', function () {
    config(['keystone.require_second_factor' => true]);
    $user = $this->createUser();
    $this->passFirstFactor($user);

    $this->travel(7)->minutes();
    $this->artisan('keystone:alert-abandoned-challenges');

    expect($this->securityEvents()->for($user)->where('type', SecurityEventType::CHALLENGE_ABANDONED))->toBeEmpty();
});
