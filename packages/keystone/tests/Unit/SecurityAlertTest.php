<?php

use Carbon\CarbonImmutable;
use ClaudioDekker\Keystone\Actor;
use ClaudioDekker\Keystone\Device;
use ClaudioDekker\Keystone\IpLocation;
use ClaudioDekker\Keystone\Notifications\SecurityAlert;
use ClaudioDekker\Keystone\SecurityEvent;
use ClaudioDekker\Keystone\SecurityEventType;
use ClaudioDekker\Keystone\SessionInfo;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Exceptions;

function alertEvent(array $attributes = []): SecurityEvent
{
    return new SecurityEvent([
        'occurred_at' => now(),
        'type' => SecurityEventType::SESSIONS_TERMINATED,
        'actor' => Actor::OPERATOR,
        'ip_address' => '203.0.113.7',
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) Gecko/20100101 Firefox/130.0',
        ...$attributes,
    ]);
}

function renderedAlert(SecurityEvent $event): string
{
    $notifiable = (new AnonymousNotifiable)->route('mail', 'jane@example.com');

    return (string) (new SecurityAlert($event))->toMail($notifiable)->render();
}

function locatingAs(?string $location): void
{
    app()->instance(IpLocation::class, new class($location) implements IpLocation
    {
        public function __construct(public ?string $location) {}

        public function locate(string $ipAddress): ?string
        {
            return $this->location;
        }
    });
}

function describingAs(?Device $device): void
{
    app()->instance(SessionInfo::class, new class($device) implements SessionInfo
    {
        public function __construct(public ?Device $device) {}

        public function describe(string $userAgent): ?Device
        {
            return $this->device;
        }
    });
}

test('the alert is a queued mail, encrypted on the queue', function () {
    $alert = new SecurityAlert(alertEvent());

    expect($alert)->toBeInstanceOf(ShouldQueue::class)
        ->toBeInstanceOf(ShouldBeEncrypted::class)
        ->and($alert->via(new AnonymousNotifiable))->toBe(['mail']);
});

it('is worded by its type\'s translation keys', function () {
    $notifiable = (new AnonymousNotifiable)->route('mail', 'jane@example.com');

    $mail = (new SecurityAlert(alertEvent()))->toMail($notifiable);

    expect($mail->subject)->toBe(__('keystone::alerts.types.sessions.terminated.subject'))
        ->and(renderedAlert(alertEvent()))->toContain(e(__('keystone::alerts.types.sessions.terminated.what')), e(__('keystone::alerts.review')));
});

it('says when, in UTC, from which IP address and where', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-30 14:05:00', 'Europe/Amsterdam'));
    locatingAs('Amsterdam, Netherlands');

    $mail = renderedAlert(alertEvent());

    expect($mail)->toContain('2026-09-30 12:05 UTC', '203.0.113.7', 'Amsterdam, Netherlands');
});

it('says the IP address is unknown when the event has none', function () {
    app()->instance(IpLocation::class, Mockery::mock(IpLocation::class)->shouldNotReceive('locate')->getMock());

    $mail = renderedAlert(alertEvent(['ip_address' => null]));

    expect($mail)->toContain(e(__('keystone::alerts.unknown')))
        ->not->toContain(e(__('keystone::alerts.fields.location')));
});

it('leaves the location out when the IP-location port throws', function () {
    app()->instance(IpLocation::class, Mockery::mock(IpLocation::class)->shouldReceive('locate')->andThrow(new RuntimeException('Lookup failed.'))->getMock());

    $mail = renderedAlert(alertEvent());

    expect($mail)->toContain('203.0.113.7')
        ->not->toContain(e(__('keystone::alerts.fields.location')));
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Lookup failed.');
});

it('shows the parsed platform and browser, never the raw user agent', function () {
    describingAs(new Device(platform: 'Windows', browser: 'Firefox'));

    $mail = renderedAlert(alertEvent());

    expect($mail)->toContain(e(__('keystone::alerts.device', ['browser' => 'Firefox', 'platform' => 'Windows'])))
        ->not->toContain('Mozilla/5.0');
});

it('shows an unknown device when the session-info port can\'t name one', function (?string $userAgent) {
    describingAs(null);

    $mail = renderedAlert(alertEvent(['user_agent' => $userAgent]));

    expect($mail)->toContain(e(__('keystone::alerts.unknown_device')));
})->with(['an unparsed user agent' => ['curl/8.0'], 'no user agent' => [null]]);

it('names the type of the credential involved, never the label its owner typed', function () {
    $mail = renderedAlert(alertEvent(['credential_type' => 'passkey', 'credential_label' => 'Work laptop https://evil.test']));

    expect($mail)->toContain('passkey')
        ->not->toContain('Work laptop', 'evil.test');
});

it('escapes every value, and carries no links', function () {
    describingAs(new Device(platform: '<b>Windows</b>', browser: 'Firefox'));
    locatingAs('<a href="https://evil.test">Amsterdam</a>');

    $mail = renderedAlert(alertEvent());

    expect($mail)->toContain('&lt;a href=&quot;https://evil.test&quot;&gt;Amsterdam&lt;/a&gt;', '&lt;b&gt;Windows&lt;/b&gt;')
        ->not->toContain('<a ', '<b>', 'href="');
});
