<?php

use ClaudioDekker\Keystone\Actions\CreateAccount;
use ClaudioDekker\Keystone\Addresses;
use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\AppTests\Assertions\EnrollmentAssertions;
use ClaudioDekker\Keystone\AppTests\Assertions\RecoveryCodesAssertions;
use ClaudioDekker\Keystone\AppTests\Assertions\RegistrationAssertions;
use ClaudioDekker\Keystone\AppTests\Assertions\RegistrationFinishAssertions;
use ClaudioDekker\Keystone\AppTests\Assertions\RegistrationLinkAssertions;
use ClaudioDekker\Keystone\AppTests\Support\CredentialTypeSupport;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\RecoveryCodeType;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Notifications\EmailedLinkMail;
use ClaudioDekker\Keystone\Notifications\SecurityAlert;
use ClaudioDekker\Keystone\Notifications\Welcome;
use ClaudioDekker\Keystone\PendingStage;
use ClaudioDekker\Keystone\Registering;
use ClaudioDekker\Keystone\SecurityEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

pest()->extend(AppTestCase::class)->use(
    AppTestCase::assertions(EnrollmentAssertions::class),
    AppTestCase::assertions(RecoveryCodesAssertions::class),
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

it('takes an address without a link when email verification is off, answering a taken one exactly as a free one and alerting its owner', function () {
    config(['keystone.email_verification.required' => false]);
    $owner = $this->createAccount('jane@example.com');

    $this->assertIndistinguishable(
        fn () => tap($this->post(route('register.submit'), ['email' => 'jane@example.com']), $this->assertRegistrationStarted(...)),
        fn () => tap($this->post(route('register.submit'), ['email' => 'new@example.com']), $this->assertRegistrationStarted(...)),
    );

    expect(Keystone::guard()->registration())->address->toBe('new@example.com')->verified->toBeFalse();
    Notification::assertNotSentTo(new AnonymousNotifiable, EmailedLinkMail::class);
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

it('creates the account when the registration finishes with each type serving it, and signs it in', function () {
    $this->eachSupportFor(Surface::REGISTRATION, function (CredentialTypeSupport $support) {
        $address = "new-{$support->type()}@example.com";
        $this->registerAddress($address);

        $response = $this->finishRegistration($support);

        $this->assertRegistered($response, '/');
        $account = Keystone::guard()->user();
        expect($account)->not->toBeNull()
            ->and((new Addresses($account))->recipientsOf($account))->toBe([$address])
            ->and(Keystone::guard()->registration())->toBeNull();
        $this->assertDatabaseHas('user_security_events', ['type' => 'account.registered', 'user_id' => $account->getKey(), 'flow' => 'registration', 'credential_type' => $support->type()]);
        Notification::assertSentOnDemand(Welcome::class, fn (Welcome $mail, $channels, $notifiable) => $notifiable->routes['mail'] === $address);
        Notification::assertNotSentTo(new AnonymousNotifiable, SecurityAlert::class);
    });
});

it('holds a new account that owes enrollment for it', function () {
    $this->withMandates();
    $support = $this->supportsFor(Surface::REGISTRATION)[0];
    $this->registerAddress();

    $response = $this->finishRegistration($support);

    $this->assertRegistrationEnrollmentOwed($response);
    expect(Keystone::guard()->check())->toBeFalse()
        ->and(Keystone::guard()->pending()?->stage)->toBe(PendingStage::ENROLLMENT);
});

it('refuses an invalid finish, creating nothing and keeping the registration', function () {
    $support = $this->supportsFor(Surface::REGISTRATION)[0];
    $this->registerAddress();

    $response = $this->post(route('register.finish.submit', ['type' => $support->type()]), $support->validEnrollment(null));

    $this->assertRegistrationFinishInvalid($response, ['name']);
    expect(Keystone::guard()->registration())->not->toBeNull();
    $this->assertDatabaseMissing('user_emails', ['address' => 'new@example.com']);
});

it('creates nothing for an address an active account came to hold since its link was spent, and ends the registration', function () {
    $support = $this->supportsFor(Surface::REGISTRATION)[0];
    $this->registerAddress();
    $owner = $this->createAccount('new@example.com');

    $response = $this->finishRegistration($support);

    $this->assertAddressTaken($response);
    expect(Keystone::guard()->registration())->toBeNull()
        ->and(Keystone::guard()->check())->toBeFalse()
        ->and((new Addresses($owner))->heldBy($owner))->toBe(['new@example.com']);
    $this->assertDatabaseMissing('user_security_events', ['type' => 'account.registered']);
});

it('creates nothing once the registration\'s window has ended', function () {
    $support = $this->supportsFor(Surface::REGISTRATION)[0];
    $this->registerAddress();
    $this->travel(Registering::WINDOW_SECONDS)->seconds();

    $this->assertRegistrationExpired($this->finishRegistration($support));
    $this->assertDatabaseMissing('user_emails', ['address' => 'new@example.com']);
});

it('refuses an account the app\'s CreateAccount created barred as a refused sign-in, ending the registration', function () {
    $support = $this->supportsFor(Surface::REGISTRATION)[0];
    $this->app->bind(CreateAccount::class, fn () => new class extends CreateAccount
    {
        public function handle(array $profile): Model
        {
            return tap(parent::handle($profile), fn (Model $account) => $account->forceFill(['suspended_at' => now()])->save());
        }
    });
    $this->registerAddress();

    $this->assertRegistrationBarred($this->finishRegistration($support));
    expect(Keystone::guard()->check())->toBeFalse()
        ->and(Keystone::guard()->registration())->toBeNull();
});

it('refuses to finish while registration is closed, creating nothing', function () {
    $support = $this->supportsFor(Surface::REGISTRATION)[0];
    $this->registerAddress();
    config(['keystone.methods' => []]);

    $this->assertRegistrationUnavailable($this->finishRegistration($support));
    $this->assertDatabaseMissing('user_emails', ['address' => 'new@example.com']);
});

it('cancels a registration before the account exists, creating nothing', function () {
    $support = $this->supportsFor(Surface::REGISTRATION)[0];
    $this->registerAddress();

    $this->assertRegistrationCancelled($this->delete(route('register.finish.cancel')));

    expect(Keystone::guard()->registration())->toBeNull();
    $this->assertSentBackToRegister($this->finishRegistration($support));
    $this->assertDatabaseMissing('user_emails', ['address' => 'new@example.com']);
});

it('signs out at the enrollment a new account owes, keeping the account', function () {
    $this->withMandates();
    $support = $this->supportsFor(Surface::REGISTRATION)[0];
    $this->registerAddress();
    $this->finishRegistration($support);

    $this->assertRegistrationEnrollmentCancelled($this->delete(route('login.enrollment.cancel')));

    expect(Keystone::guard()->check())->toBeFalse()
        ->and(Keystone::guard()->pending())->toBeNull();
    $this->assertDatabaseHas('user_emails', ['address' => 'new@example.com']);
});

it('records enrollment.completed once a new account has enrolled what it owes, and signs it in without a new-device alert', function () {
    $this->withMandates();
    $this->registerAddress();
    $this->finishRegistration($this->supportsFor(Surface::REGISTRATION)[0]);
    $this->enrollSecondFactor($this->enrollmentSupports()[0]);
    $this->get(route('login.recovery-codes'));

    $response = $this->post(route('login.recovery-codes.submit'), [RecoveryCodeType::FIELD => $this->stagedRecoveryCodes()[0]]);

    $this->assertRecoveryCodesSaved($response, '/');
    $account = Keystone::guard()->user();
    expect($account)->not->toBeNull();
    $this->assertDatabaseHas('user_security_events', ['type' => 'enrollment.completed', 'user_id' => $account->getKey(), 'flow' => 'enrollment']);
    Notification::assertNotSentTo(new AnonymousNotifiable, SecurityAlert::class, fn (SecurityAlert $alert) => $alert->type === SecurityEventType::SIGNED_IN);
});

it('sends a signed-in user away from the finish, creating nothing', function () {
    $support = $this->supportsFor(Surface::REGISTRATION)[0];
    $this->registerAddress();
    $this->signInAccount($this->supportsFor(Surface::SIGN_IN)[0], 'jane@example.com');

    $this->assertSentAwayFromRegistration($this->finishRegistration($support));
    $this->assertDatabaseMissing('user_emails', ['address' => 'new@example.com']);
});
