<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\AppTests\Assertions\RegistrationAssertions;
use ClaudioDekker\Keystone\AppTests\Assertions\RegistrationFinishAssertions;
use ClaudioDekker\Keystone\AppTests\Assertions\RegistrationLinkAssertions;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Notifications\EmailedLinkMail;
use ClaudioDekker\Keystone\Notifications\SecurityAlert;
use ClaudioDekker\Keystone\Registering;
use ClaudioDekker\Keystone\SecurityEventType;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

pest()->extend(AppTestCase::class)->use(
    AppTestCase::assertions(RegistrationAssertions::class),
    AppTestCase::assertions(RegistrationLinkAssertions::class),
    AppTestCase::assertions(RegistrationFinishAssertions::class),
);

beforeEach(function () {
    $this->withoutMandates();
    $this->supportsFor(Surface::REGISTRATION);
    Notification::fake();
});

it('mails a free address a link whose step changes nothing, and whose button starts registering the address', function () {
    $page = $this->get(route('register'));
    $submitted = $this->post(route('register.submit'), ['email' => 'New@Example.com']);
    $sent = $this->get(route('register.link-sent'));
    $url = $this->mailedLinkTo('new@example.com');
    $sessionId = session()->getId();
    $opened = $this->get($url);

    $this->assertRegistrationPage($page);
    $this->assertRegistrationLinkSent($submitted);
    $this->assertRegistrationLinkSentPage($sent);
    $this->assertRegistrationLinkPage($opened);
    $this->assertEmailedLinkHardeningFloor($opened);
    expect($url)->toStartWith(rtrim((string) config('app.url'), '/').'/')
        ->and(Keystone::guard()->registration())->toBeNull()
        ->and(session()->getId())->toBe($sessionId);

    $spent = $this->post($url);
    $finish = $this->get(route('register.finish'));

    $this->assertRegistrationLinkConsumed($spent);
    $this->assertRegistrationFinishPage($finish, 'new@example.com');
    expect(session()->getId())->not->toBe($sessionId);
});

it('points a link requested under a foreign Host at app.url, and refuses it replayed under one', function () {
    $this->post('http://evil.example'.route('register.submit', absolute: false), ['email' => 'new@example.com']);
    $url = $this->mailedLinkTo('new@example.com');
    $foreign = preg_replace('#^https?://[^/]+#', 'http://evil.example', $url);

    $this->assertRegistrationLinkExpired($this->post($foreign));
    expect($url)->toStartWith(rtrim((string) config('app.url'), '/').'/')
        ->and(Keystone::guard()->registration())->toBeNull();
});

it('takes a link once', function () {
    $this->post(route('register.submit'), ['email' => 'new@example.com']);
    $url = $this->mailedLinkTo('new@example.com');
    $this->post($url);
    $this->app['session.store']->invalidate();

    $this->assertRegistrationLinkExpired($this->post($url));
    expect(Keystone::guard()->registration())->toBeNull();
});

it('refuses a tampered link like a used one', function () {
    $this->post(route('register.submit'), ['email' => 'new@example.com']);
    $url = $this->mailedLinkTo('new@example.com');

    $this->assertRegistrationLinkExpired($this->post(preg_replace('/signature=[0-9a-f]+/', 'signature='.str_repeat('0', 64), $url)));
    $this->assertRegistrationLinkExpired($this->post(str_replace('token=', 'token=x', $url)));
    expect(Keystone::guard()->registration())->toBeNull();
});

it('mails nothing to an address an active account holds, and alerts that account instead, answering exactly as for a free one', function () {
    $owner = $this->createAccount('jane@example.com');

    $this->assertIndistinguishable(
        fn () => tap($this->post(route('register.submit'), ['email' => 'jane@example.com']), $this->assertRegistrationLinkSent(...)),
        fn () => tap($this->post(route('register.submit'), ['email' => 'new@example.com']), $this->assertRegistrationLinkSent(...)),
    );

    Notification::assertNotSentTo(new AnonymousNotifiable, EmailedLinkMail::class, fn ($mail, $channels, $notifiable) => $notifiable->routes['mail'] === 'jane@example.com');
    Notification::assertSentOnDemand(SecurityAlert::class, fn (SecurityAlert $alert, $channels, $notifiable) => $alert->type === SecurityEventType::ADDRESS_CLAIM_ATTEMPTED && $notifiable->routes['mail'] === 'jane@example.com');
    $this->assertDatabaseHas('user_security_events', ['type' => 'address.claim_attempted', 'user_id' => $owner->getKey(), 'flow' => 'registration']);
});

it('waits out the timing floor for a free and a taken address', function (string $address) {
    $this->createAccount('jane@example.com');

    $this->assertRegistrationLinkSent($this->assertWaitsOutTimingFloor(fn () => $this->post(route('register.submit'), ['email' => $address])));
})->with(['free' => 'new@example.com', 'taken' => 'jane@example.com']);

it('mails an address no more links than the delivery limit allows, answering exactly as before', function () {
    foreach (range(1, config()->integer('keystone.rate_limits.deliveries_per_ten_minutes')) as $ignored) {
        $this->post(route('register.submit'), ['email' => 'new@example.com']);
    }

    $this->assertIndistinguishable(
        fn () => tap($this->post(route('register.submit'), ['email' => 'new@example.com']), $this->assertRegistrationLinkSent(...)),
        fn () => tap($this->post(route('register.submit'), ['email' => 'other@example.com']), $this->assertRegistrationLinkSent(...)),
    );

    Notification::assertSentOnDemandTimes(EmailedLinkMail::class, config()->integer('keystone.rate_limits.deliveries_per_ten_minutes') + 1);
});

it('refuses an invalid address', function () {
    $this->assertRegistrationInvalid($this->post(route('register.submit'), ['email' => 'not-an-address']), ['email']);
});

it('ends the registration window 30 minutes after the link was spent', function () {
    $this->post(route('register.submit'), ['email' => 'new@example.com']);
    $this->post($this->mailedLinkTo('new@example.com'));

    $this->travel(Registering::WINDOW_SECONDS)->seconds();

    $this->assertSentBackToRegister($this->get(route('register.finish')));
});

it('sends a session that proved no address back to register', function () {
    $this->assertSentBackToRegister($this->get(route('register.finish')));
});

it('refuses every registration step while no listed type serves registration, mailing nothing', function () {
    config(['keystone.methods' => []]);

    $this->assertRegistrationUnavailable($this->get(route('register')));
    $this->assertRegistrationUnavailable($this->post(route('register.submit'), ['email' => 'new@example.com']));
    $this->assertRegistrationUnavailable($this->get(route('register.link-sent')));
    $this->assertRegistrationUnavailable($this->get(route('register.verify')));
    $this->assertRegistrationUnavailable($this->post(route('register.verify.consume')));
    $this->assertRegistrationUnavailable($this->get(route('register.link-expired')));
    $this->assertRegistrationUnavailable($this->get(route('register.finish')));
    Notification::assertNothingSent();
});

it('sends a signed-in user away from every registration step', function () {
    $this->signInAccount($this->supportsFor(Surface::SIGN_IN)[0]);

    $this->assertSentAwayFromRegistration($this->get(route('register')));
    $this->assertSentAwayFromRegistration($this->post(route('register.submit'), ['email' => 'new@example.com']));
    $this->assertSentAwayFromRegistration($this->get(route('register.finish')));
    Notification::assertNotSentTo(new AnonymousNotifiable, EmailedLinkMail::class);
});
