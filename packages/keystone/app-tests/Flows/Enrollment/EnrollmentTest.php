<?php

use ClaudioDekker\Keystone\AppTests\Support\CredentialTypeSupport;
use ClaudioDekker\Keystone\Jobs\EndSessions;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\RateLimits\StepKind;
use ClaudioDekker\Keystone\RecoveryCodes\RecoveryCode;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use ClaudioDekker\Keystone\Session\Flow;
use Illuminate\Support\Facades\Notification;

it('holds a correct first factor for enrollment when the account holds no second factor', function () {
    $user = $this->createUser();

    $response = $this->passFirstFactor($user);

    $this->assertHeldForEnrollment($response);
});

it('signs the held user in once they type back one of the recovery codes it shows', function () {
    $user = $this->createUser();
    $support = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);
    $this->answerChallenge($user, $support);
    $codes = $this->shownRecoveryCodes($this->get(route('login.enrollment')));

    $response = $this->post(route('login.enrollment.submit', 'recovery-code'), ['recovery_code' => $codes[5]]);

    $this->assertSignedIn($response, '/');
});

it('shows the same recovery codes again on a reload', function () {
    $user = $this->createUser();
    $support = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);
    $this->answerChallenge($user, $support);
    $shown = $this->shownRecoveryCodes($this->get(route('login.enrollment')));

    $reloaded = $this->shownRecoveryCodes($this->get(route('login.enrollment')));

    expect($reloaded)->toBe($shown)
        ->and($shown)->toHaveCount(8);
});

it('refuses a code the recovery-code step never showed, saving nothing', function () {
    $user = $this->createUser();
    $support = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);
    $this->answerChallenge($user, $support);
    $this->get(route('login.enrollment'));

    $response = $this->post(route('login.enrollment.submit', 'recovery-code'), ['recovery_code' => RecoveryCode::generate()->format()]);

    $this->assertEnrollmentRefused($response, 'recovery_code', __('keystone::messages.recovery-code-incorrect'));
    expect($this->securityEvents()->for($user)->pluck('type')->all())->not->toContain(SecurityEventType::RECOVERY_CODES_GENERATED);
});

it('saves the shown codes, so one of them answers a later challenge', function () {
    $user = $this->createUser();
    $support = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);
    $this->answerChallenge($user, $support);
    $codes = $this->shownRecoveryCodes($this->get(route('login.enrollment')));
    $this->post(route('login.enrollment.submit', 'recovery-code'), ['recovery_code' => $codes[2]]);

    $this->switchToFreshBrowser();
    $this->passFirstFactor($user);
    $response = $this->post(route('login.challenge.submit', 'recovery-code'), ['recovery_code' => $codes[6]]);

    $this->assertSignedIn($response, '/');
});

it('saves only the shown codes, so a later challenge refuses a code the step never showed', function () {
    $user = $this->createUser();
    $support = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);
    $this->answerChallenge($user, $support);
    $codes = $this->shownRecoveryCodes($this->get(route('login.enrollment')));
    $this->post(route('login.enrollment.submit', 'recovery-code'), ['recovery_code' => $codes[2]]);

    $this->switchToFreshBrowser();
    $this->passFirstFactor($user);
    $response = $this->post(route('login.challenge.submit', 'recovery-code'), ['recovery_code' => RecoveryCode::generate()->format()]);

    $this->assertChallengeRefused($response, 'recovery_code', __('keystone::messages.credential-invalid'));
});

it('records the saved codes in the enrollment flow, alerting nobody for a first set', function () {
    Notification::fake();
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);
    $this->answerChallenge($user, $support);
    $codes = $this->shownRecoveryCodes($this->get(route('login.enrollment')));

    $this->post(route('login.enrollment.submit', 'recovery-code'), ['recovery_code' => $codes[0]]);

    $generated = $this->securityEvents()->for($user)->firstWhere('type', SecurityEventType::RECOVERY_CODES_GENERATED);

    expect($generated->flow)->toBe(Flow::ENROLLMENT)
        ->and($this->sentAlerts())->toBeEmpty();
});

it('offers every type the account can enroll that also answers the challenge, the first one preselected', function () {
    $user = $this->createUser();
    $enrollable = collect($this->supportsFor(Surface::ENROLLMENT))
        ->map(fn (CredentialTypeSupport $support) => $support->type())
        ->first(fn (CredentialType $type) => $type->initiateShape(Surface::CHALLENGE) !== null)
        ->name();

    $this->passFirstFactor($user);

    $response = $this->get(route('login.enrollment'));

    $this->assertEnrollmentPage($response, [
        'preselected' => $enrollable,
        'types.0.name' => $enrollable,
    ]);
});

it('cancels the pending sign-in, back at sign-in with the cancelled status', function () {
    $user = $this->createUser();
    $this->passFirstFactor($user);
    $this->get(route('login.enrollment'));

    $response = $this->delete(route('login.enrollment.cancel'));

    $page = $this->get(route('login'));
    $next = $this->get(route('login.enrollment'));

    $this->assertEnrollmentCancelled($response);
    $this->assertSignInPage($page, ['status' => __('keystone::messages.enrollment-cancelled')]);
    $this->assertNoPendingSignIn($next);
});

it('counts enrollment cancels against the change allowance', function () {
    foreach (range(1, $this->requestAllowance(StepKind::CHANGE)) as $i) {
        $this->delete(route('login.enrollment.cancel'));
    }

    $response = $this->delete(route('login.enrollment.cancel'));

    $this->assertThrottled($response);
});

it('sends a session without a pending sign-in at enrollment to sign in', function (Closure $step) {
    $response = $step($this);

    $this->assertNoPendingSignIn($response);
})->with([
    'the enrollment page' => [fn ($test) => $test->get(route('login.enrollment'))],
    'an answer' => [fn ($test) => $test->post(route('login.enrollment.submit', 'recovery-code'), ['recovery_code' => RecoveryCode::generate()->format()])],
]);

it('sends a session held at the challenge to sign in from enrollment', function () {
    $user = $this->createUser();
    $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);

    $response = $this->get(route('login.enrollment'));

    $this->assertNoPendingSignIn($response);
});

it('drops the hold once a second factor is added elsewhere, though the account still owes its codes, and challenges the next sign-in', function () {
    $user = $this->createUser();
    $this->passFirstFactor($user);

    $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $dropped = $this->get(route('login.enrollment'));

    $this->switchToFreshBrowser();
    $next = $this->passFirstFactor($user);

    $this->assertNoPendingSignIn($dropped);
    $this->assertHeldForChallenge($next);
});

it("drops a pending sign-in at enrollment once its account's credential epoch moved, and records it voided", function () {
    $user = $this->createUser();
    $this->passFirstFactor($user);

    EndSessions::dispatchSync($user, operator: 'margaret');
    $response = $this->get(route('login.enrollment'));

    $this->assertNoPendingSignIn($response);
    expect($this->securityEvents()->for($user)->last()->type)->toBe(SecurityEventType::SIGN_IN_VOIDED);
});

it('ends a pending sign-in at enrollment 15 minutes after its hold', function () {
    $user = $this->createUser();
    $this->passFirstFactor($user);

    $this->travel(15)->minutes();
    $response = $this->get(route('login.enrollment'));

    $this->assertNoPendingSignIn($response);
});

it('signs in once its codes are saved when no second factor is required', function () {
    config(['keystone.require_second_factor' => false]);
    $user = $this->createUser();
    $this->passFirstFactor($user);
    $codes = $this->shownRecoveryCodes($this->get(route('login.enrollment')));

    $response = $this->post(route('login.enrollment.submit', 'recovery-code'), ['recovery_code' => $codes[1]]);

    $this->assertSignedIn($response, '/');
});

it('owes no recovery codes while they are not required', function () {
    config(['keystone.require_recovery_codes' => false]);
    $user = $this->createUser();
    $support = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);

    $response = $this->answerChallenge($user, $support);

    $this->assertSignedIn($response, '/');
});

it('lands on the URL the user was going to before the first factor', function () {
    config(['keystone.require_second_factor' => false]);
    $user = $this->createUser();
    $this->withSession(['url.intended' => '/reports?period=30d']);
    $this->passFirstFactor($user);
    $codes = $this->shownRecoveryCodes($this->get(route('login.enrollment')));

    $response = $this->post(route('login.enrollment.submit', 'recovery-code'), ['recovery_code' => $codes[1]]);

    $this->assertSignedIn($response, '/reports?period=30d');
});

it('treats a session held at enrollment as a guest on every other page', function () {
    $user = $this->createUser();
    $this->passFirstFactor($user);

    $protected = $this->getJson($this->protectedPage());

    expect($protected->getStatusCode())->toBe(401);
});
