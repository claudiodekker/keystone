<?php

use ClaudioDekker\Keystone\AppTests\IpLocationFake;
use ClaudioDekker\Keystone\AppTests\SessionInfoFake;
use ClaudioDekker\Keystone\AppTests\Support\AppAlert;
use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Contracts\IpLocation;
use ClaudioDekker\Keystone\Contracts\SessionInfo;
use ClaudioDekker\Keystone\Devices\Device;
use ClaudioDekker\Keystone\Jobs\EndSessions;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Models\UserEmail;
use ClaudioDekker\Keystone\Notifications\SecurityAlert;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;

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

it('sends nothing for a type whose slot the app set to null', function () {
    config(['keystone.notifications' => [...config('keystone.notifications'), 'sessions.terminated' => null]]);
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');

    Artisan::call('keystone:end-sessions', ['user' => $user->getAuthIdentifier(), '--operator' => 'margaret']);

    expect($this->sentAlerts())->toBeEmpty()
        ->and($this->securityEvents()->for($user)->pluck('type')->all())->toBe([SecurityEventType::SESSIONS_TERMINATED]);
});

it("sends the app's own class in place of Keystone's, built from the event", function () {
    config(['keystone.notifications' => [...config('keystone.notifications'), 'sessions.terminated' => AppAlert::class]]);
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');

    Artisan::call('keystone:end-sessions', ['user' => $user->getAuthIdentifier(), '--operator' => 'margaret']);

    $alerts = $this->sentAlerts();

    expect($alerts->pluck('address')->all())->toBe(['ada@example.com'])
        ->and($alerts[0]['alert'])->toBeInstanceOf(AppAlert::class)
        ->and($alerts[0]['alert']->event->type)->toBe(SecurityEventType::SESSIONS_TERMINATED)
        ->and($alerts[0]['alert']->event->operator)->toBe('margaret');
});

it("mails the account's addresses for a type that sends nothing by default once the app gives it a class", function () {
    $this->withoutEnrollmentMandates();
    config(['keystone.notifications' => [...config('keystone.notifications'), 'signed_out' => AppAlert::class]]);
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->post(route('logout'));

    $alerts = $this->sentAlerts();

    expect($alerts->pluck('address')->all())->toBe(['ada@example.com'])
        ->and($alerts[0]['alert']->event->type)->toBe(SecurityEventType::SIGNED_OUT);
});

it('queues the alert encrypted, with the device parsed from the user agent and never the user agent itself', function () {
    $userAgent = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
    $sessionInfo = new SessionInfoFake;
    $sessionInfo->knows($userAgent, new Device(platform: 'Mac', browser: 'Chrome'));
    $this->app->instance(SessionInfo::class, $sessionInfo);
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    Route::post('/admin/end-sessions', fn () => EndSessions::dispatchSync($user, operator: 'margaret'));

    $this->withHeader('User-Agent', $userAgent)->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->post('/admin/end-sessions');

    $alert = $this->sentAlerts()->sole()['alert'];

    expect($alert)->toBeInstanceOf(ShouldQueue::class)
        ->and($alert)->toBeInstanceOf(ShouldBeEncrypted::class)
        ->and($alert->ipAddress)->toBe('203.0.113.9')
        ->and($alert->device)->toEqual(new Device(platform: 'Mac', browser: 'Chrome'))
        ->and(serialize($alert))->not->toContain('Mozilla');
});

it('looks up no location while the request that caused the alert runs', function () {
    $ipLocation = new IpLocationFake;
    $this->app->instance(IpLocation::class, $ipLocation);
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    Route::post('/admin/end-sessions', fn () => EndSessions::dispatchSync($user, operator: 'margaret'));

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->post('/admin/end-sessions');

    expect($this->sentAlerts())->toHaveCount(1);
    $ipLocation->assertNothingLocated();
});
