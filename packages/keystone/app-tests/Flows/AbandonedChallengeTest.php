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
