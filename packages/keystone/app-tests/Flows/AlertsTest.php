<?php

use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Jobs\EndSessions;
use ClaudioDekker\Keystone\Models\UserEmail;
use ClaudioDekker\Keystone\Notifications\SecurityAlert;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
});

it("mails each of the account's verified addresses when an operator ends its sessions", function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $this->addVerifiedAddress($user, 'ada@work.example');

    Artisan::call('keystone:end-sessions', ['user' => $user->getAuthIdentifier(), '--operator' => 'margaret']);

    $alerts = $this->sentAlerts();

    expect($alerts->pluck('address')->sort()->values()->all())->toBe(['ada@example.com', 'ada@work.example'])
        ->and($alerts->pluck('alert')->every(fn ($alert) => $alert instanceof SecurityAlert && $alert->type === SecurityEventType::SESSIONS_TERMINATED))->toBeTrue();
});

it("sends no alert when the operator ends the account's sessions without one", function (Closure $endSessionsWithoutAlert) {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');

    $endSessionsWithoutAlert($user);

    expect($this->sentAlerts())->toBeEmpty()
        ->and($this->securityEvents()->for($user)->pluck('type')->all())->toBe([SecurityEventType::SESSIONS_TERMINATED]);
})->with([
    'with the command' => [fn (KeystoneUser $user) => Artisan::call('keystone:end-sessions', ['user' => $user->getAuthIdentifier(), '--operator' => 'margaret', '--no-alert' => true])],
    'through the job an admin panel dispatches' => [fn (KeystoneUser $user) => EndSessions::dispatch($user, operator: 'margaret')->withoutAlert()],
]);

it("alerts nobody when the operator ends every account's sessions", function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');

    Artisan::call('keystone:end-sessions', ['--all' => true, '--operator' => 'margaret']);

    expect($this->sentAlerts())->toBeEmpty();
});

it('mails the unverified addresses of an account that holds no verified address', function () {
    $user = $this->createUser();
    $this->addUnverifiedAddress($user, 'ada@example.com');
    $this->addUnverifiedAddress($user, 'ada@work.example');

    Artisan::call('keystone:end-sessions', ['user' => $user->getAuthIdentifier(), '--operator' => 'margaret']);

    $alerts = $this->sentAlerts();

    expect($alerts->pluck('address')->sort()->values()->all())->toBe(['ada@example.com', 'ada@work.example']);
});

it('mails only the verified addresses of an account that holds both kinds', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $this->addUnverifiedAddress($user, 'ada@typo.example');

    Artisan::call('keystone:end-sessions', ['user' => $user->getAuthIdentifier(), '--operator' => 'margaret']);

    $alerts = $this->sentAlerts();

    expect($alerts->pluck('address')->all())->toBe(['ada@example.com']);
});

it('skips an unverified address that another account holds verified', function () {
    $user = $this->createUser();
    $this->addUnverifiedAddress($user, 'ada@example.com');
    $this->addUnverifiedAddress($user, 'grace@example.com');
    $other = $this->createUser();
    $this->addVerifiedAddress($other, 'grace@example.com');

    Artisan::call('keystone:end-sessions', ['user' => $user->getAuthIdentifier(), '--operator' => 'margaret']);

    $alerts = $this->sentAlerts();

    expect($alerts->pluck('address')->all())->toBe(['ada@example.com']);
});

it('mails an address the account held when its sessions ended, though the transaction around the change removed it', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $this->addVerifiedAddress($user, 'ada@stolen.example');

    DB::transaction(function () use ($user) {
        EndSessions::dispatchSync($user, operator: 'margaret');

        UserEmail::query()->where('address', 'ada@stolen.example')->delete();
    });

    $alerts = $this->sentAlerts();

    expect($alerts->pluck('address')->sort()->values()->all())->toBe(['ada@example.com', 'ada@stolen.example']);
});
