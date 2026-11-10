<?php

use Carbon\CarbonImmutable;
use ClaudioDekker\Keystone\AppTests\IpLocationFake;
use ClaudioDekker\Keystone\AppTests\SessionInfoFake;
use ClaudioDekker\Keystone\AppTests\Support\RecoveryCodeSupport;
use ClaudioDekker\Keystone\Contracts\IpLocation;
use ClaudioDekker\Keystone\Contracts\SessionInfo;
use ClaudioDekker\Keystone\Devices\Device;
use ClaudioDekker\Keystone\Jobs\EndSessions;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Notifications\SecurityAlert;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config(['mail.default' => 'array', 'queue.default' => 'sync']);
});

it('mails what happened, when, from which IP address and where, and on which device', function () {
    $userAgent = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
    $sessionInfo = new SessionInfoFake;
    $sessionInfo->knows($userAgent, new Device(platform: 'Mac', browser: 'Chrome'));
    $this->app->instance(SessionInfo::class, $sessionInfo);
    $ipLocation = new IpLocationFake;
    $ipLocation->knows('203.0.113.9', 'Reykjavík, Iceland');
    $this->app->instance(IpLocation::class, $ipLocation);
    $this->travelTo(CarbonImmutable::parse('2026-03-14 09:26:53', 'UTC'));
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    Route::post('/admin/end-sessions', fn () => EndSessions::dispatchSync($user, operator: 'margaret'));

    $this->withHeader('User-Agent', $userAgent)->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->post('/admin/end-sessions');

    $mail = Mail::mailer('array')->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
    $body = $mail->getHtmlBody();

    expect($mail->getTo()[0]->getAddress())->toBe('ada@example.com')
        ->and($mail->getSubject())->toBe(__('keystone::alerts.sessions.terminated.subject'))
        ->and($body)->toContain(e(__('keystone::alerts.sessions.terminated.summary')))
        ->and($body)->toContain(e(__('keystone::alerts.when', ['time' => '2026-03-14 09:26'])))
        ->and($body)->toContain(e(__('keystone::alerts.ip-address-at', ['ip_address' => '203.0.113.9', 'location' => 'Reykjavík, Iceland'])))
        ->and($body)->toContain(e(__('keystone::alerts.device', ['device' => __('keystone::alerts.device-name', ['browser' => 'Chrome', 'platform' => 'Mac'])])))
        ->and($body)->toContain(e(__('keystone::alerts.if-not-you')))
        ->and($body)->not->toContain('Mozilla');
});

it('says the device is unknown, and names the IP address alone, when neither is known', function () {
    $userAgent = 'curl/8.7.1';
    $this->app->instance(SessionInfo::class, new SessionInfoFake);
    $this->app->instance(IpLocation::class, new IpLocationFake);
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    Route::post('/admin/end-sessions', fn () => EndSessions::dispatchSync($user, operator: 'margaret'));

    $this->withHeader('User-Agent', $userAgent)->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->post('/admin/end-sessions');

    $body = Mail::mailer('array')->getSymfonyTransport()->messages()->sole()->getOriginalMessage()->getHtmlBody();

    expect($body)->toContain(e(__('keystone::alerts.device', ['device' => __('keystone::alerts.unknown-device')])))
        ->and($body)->toContain(e(__('keystone::alerts.ip-address', ['ip_address' => '203.0.113.9'])))
        ->and($body)->not->toContain('curl');
});

it('says how many recovery codes the account has left once one was spent', function () {
    $user = $this->createUser();
    $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $codes = (new RecoveryCodeSupport)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);

    $this->answerChallenge($user, $codes);

    $mail = Mail::mailer('array')->getSymfonyTransport()->messages()->sole()->getOriginalMessage();

    expect($mail->getSubject())->toBe(__('keystone::alerts.recovery_code.used.subject'))
        ->and($mail->getHtmlBody())->toContain(e(__('keystone::alerts.recovery_code.used.summary')))
        ->and($mail->getHtmlBody())->toContain(e(trans_choice('keystone::alerts.recovery_code.used.left', 7)));
});

it('keeps the IP address, the user agent and the address out of the queued payload', function () {
    $payloads = [];
    Queue::before(function (JobProcessing $event) use (&$payloads) {
        $payloads[] = $event->job->getRawBody();
    });

    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    Route::post('/admin/end-sessions', fn () => EndSessions::dispatchSync($user, operator: 'margaret'));

    $this->withHeader('User-Agent', 'Mozilla/5.0 (X11; Linux x86_64) Firefox/133.0')->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->post('/admin/end-sessions');

    $queued = implode("\n", $payloads);

    expect($queued)->toContain(json_encode(SecurityAlert::class, JSON_THROW_ON_ERROR))
        ->and($queued)->not->toContain('203.0.113.9')
        ->and($queued)->not->toContain('Firefox')
        ->and($queued)->not->toContain('ada@example.com');
});
