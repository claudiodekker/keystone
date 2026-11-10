<?php

use ClaudioDekker\Keystone\AppTests\Support\CredentialTypeSupport;
use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Jobs\EndSessions;
use ClaudioDekker\Keystone\Jobs\SuspendAccount;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\RateLimits\StepKind;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use ClaudioDekker\Keystone\Session\Flow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Sleep;

it('holds a correct first factor for the challenge when the account holds a second factor', function () {
    $user = $this->createUser();
    $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);

    $response = $this->passFirstFactor($user);

    $this->assertHeldForChallenge($response);
});

it('records the hold for the account, with the stage it owes as the reason', function () {
    $user = $this->createUser();
    $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);

    $this->passFirstFactor($user);

    $events = $this->securityEvents()->for($user);

    expect($events->pluck('type')->all())->toBe([SecurityEventType::SIGN_IN_HELD])
        ->and($events->first()->reason)->toBe('keystone.challenge')
        ->and($events->first()->credential_type)->toBe($this->supportFor(Surface::SIGN_IN)->type()->name());
});

it('treats a held session as a guest on every other page', function () {
    $user = $this->createUser();
    $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);

    $page = $this->get(route('login'));
    $protected = $this->getJson($this->protectedPage());

    $this->assertSignInPage($page);
    expect($protected->getStatusCode())->toBe(401);
});

it('replaces an earlier pending sign-in in the session with a new one', function () {
    $ada = $this->createUser();
    $grace = $this->createUser();
    $support = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $ada);
    $support->arrangeCredential(Surface::CHALLENGE, $grace);
    $this->passFirstFactor($ada);
    $this->passFirstFactor($grace);

    $response = $this->answerChallenge($grace, $support);

    $this->assertSignedIn($response, '/');
    expect($this->securityEvents()->for($grace)->last()->type)->toBe(SecurityEventType::SIGNED_IN)
        ->and($this->securityEvents()->for($ada)->pluck('type')->all())->toBe([SecurityEventType::SIGN_IN_HELD]);
});

it("signs in on the first factor alone while the second factor's type is taken off the challenge, and holds again once it is back", function () {
    $this->withoutEnrollmentMandates();
    $user = $this->createUser();
    $secondFactor = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user)->type()->name();
    $firstFactor = $this->supportFor(Surface::SIGN_IN)->type()->name();

    config(['keystone.methods' => [$firstFactor]]);
    $unlisted = $this->passFirstFactor($user);

    $this->switchToFreshBrowser();
    config(['keystone.methods' => [$firstFactor, $secondFactor]]);
    $listed = $this->passFirstFactor($user);

    $this->assertSignedIn($unlisted, '/');
    $this->assertHeldForChallenge($listed);
});

it('signs the held user in with a valid answer', function () {
    $user = $this->createUser();
    $support = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);

    $response = $this->answerChallenge($user, $support);

    Auth::forgetGuards();
    $next = $this->get(route('login'));

    $this->assertSignedIn($response, '/');
    $this->assertAlreadySignedIn($next);
});

it('records the sign-in with the type that answered the challenge', function () {
    $user = $this->createUser();
    $support = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);

    $this->answerChallenge($user, $support);

    $signedIn = $this->securityEvents()->for($user)->last();

    expect($signedIn->type)->toBe(SecurityEventType::SIGNED_IN)
        ->and($signedIn->flow)->toBe(Flow::CHALLENGE)
        ->and($signedIn->credential_type)->toBe($support->type()->name());
});

it("signs the held user in with a valid answer while the app's global scopes hide the account", function () {
    $user = $this->createUser();
    $support = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->beforeApplicationDestroyed(fn () => Model::clearBootedModels());
    $this->passFirstFactor($user);

    Keystone::userModel()::addGlobalScope('hidden', fn (Builder $query) => $query->whereKeyNot($user->getAuthIdentifier()));
    $response = $this->answerChallenge($user, $support);

    $this->assertSignedIn($response, '/');
});

it('lands on the URL the user was going to before the first factor', function () {
    $user = $this->createUser();
    $support = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->withSession(['url.intended' => '/reports?period=7d']);
    $this->passFirstFactor($user);

    $response = $this->answerChallenge($user, $support);

    $this->assertSignedIn($response, '/reports?period=7d');
});

it('gives the session a new id when it holds the sign-in', function () {
    $user = $this->createUser();
    $support = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $cookie = config('session.cookie');

    $beforeHolding = $this->get(route('login'))->getCookie($cookie)->getValue();

    $this->withCookie($cookie, $beforeHolding)->passFirstFactor($user);

    $this->switchToFreshBrowser();
    $response = $this->withCookie($cookie, $beforeHolding)->answerChallenge($user, $support);

    $this->assertNoPendingSignIn($response);
});

it('shows the second-factor types the account holds, the first one preselected', function () {
    $user = $this->createUser();
    $support = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);

    $response = $this->get(route('login.challenge'));

    $this->assertChallengePage($response, [
        'preselected' => $support->type()->name(),
        'types' => [['name' => $support->type()->name(), 'shape' => 'form']],
    ]);
});

it('shows the challenge only once the timing floor has passed', function () {
    $user = $this->createUser();
    $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);

    $this->get(route('login.challenge'));

    Sleep::assertSequence([Sleep::for(300)->milliseconds()]);
});

it('refuses a wrong answer with the message on its field', function () {
    $user = $this->createUser();
    $support = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);
    $wrong = $support->rejectedProof(Surface::CHALLENGE);

    $response = $this->post(route('login.challenge.submit', $support->type()->name()), $wrong);

    $this->assertChallengeRefused($response, array_key_first($wrong), __('keystone::messages.credential-invalid'));
});

it('keeps the pending sign-in after a wrong answer', function () {
    $user = $this->createUser();
    $support = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);
    $this->post(route('login.challenge.submit', $support->type()->name()), $support->rejectedProof(Surface::CHALLENGE));

    $response = $this->answerChallenge($user, $support);

    $this->assertSignedIn($response, '/');
});

it('records a wrong answer as a rejected proof in the challenge flow', function () {
    $user = $this->createUser();
    $support = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);

    $this->post(route('login.challenge.submit', $support->type()->name()), $support->rejectedProof(Surface::CHALLENGE));

    $rejected = $this->securityEvents()->for($user)->last();

    expect($rejected->type)->toBe(SecurityEventType::PROOF_REJECTED)
        ->and($rejected->flow)->toBe(Flow::CHALLENGE)
        ->and($rejected->credential_type)->toBe($support->type()->name());
});

it('refuses even a right answer as throttled once the wrong answers spent the allowance', function () {
    $user = $this->createUser();
    $support = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);

    foreach (range(1, $this->failedAttemptAllowance()) as $i) {
        $this->travel(7)->seconds();
        $this->withServerVariables(['REMOTE_ADDR' => "10.1.{$i}.1"])
            ->post(route('login.challenge.submit', $support->type()->name()), $support->rejectedProof(Surface::CHALLENGE));
    }

    $response = $this->withServerVariables(['REMOTE_ADDR' => '10.2.0.1'])->answerChallenge($user, $support);

    $this->assertThrottled($response);
});

it('counts challenge requests against the held account, whatever the address', function () {
    $user = $this->createUser();
    $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);

    foreach (range(1, $this->requestAllowance(StepKind::VIEW)) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => "10.1.{$i}.1"])->get(route('login.challenge'));
    }

    $response = $this->withServerVariables(['REMOTE_ADDR' => '10.2.0.1'])->get(route('login.challenge'));

    $this->assertThrottled($response);
});

it('records a spent request limit on the challenge against the held account', function () {
    $user = $this->createUser();
    $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);

    foreach (range(1, $this->requestAllowance(StepKind::VIEW)) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => "10.1.{$i}.1"])->get(route('login.challenge'));
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.2.0.1'])->get(route('login.challenge'));

    $tripped = $this->securityEvents()->for($user)->last();

    expect($tripped->type)->toBe(SecurityEventType::LIMIT_TRIPPED)
        ->and($tripped->reason)->toBe('keystone.request_limit');
});

it('gives a held first factor its own count back, and no more', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);
    $this->submitRejectedProofs($support, 'ada@example.com', $this->failedAttemptAllowance() - 1);

    $held = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertHeldForChallenge($held);

    $this->switchToFreshBrowser();
    $givenBack = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    $this->assertSignInRefused($givenBack, __('keystone::messages.credentials-rejected'));

    $this->switchToFreshBrowser();
    $spent = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    $this->assertSignInThrottled($spent);
});

it('gives a right answer its own count back, and no more', function () {
    $user = $this->createUser();
    $support = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);

    foreach (range(1, $this->failedAttemptAllowance() - 1) as $i) {
        $this->travel(7)->seconds();
        $this->withServerVariables(['REMOTE_ADDR' => "10.1.{$i}.1"])
            ->post(route('login.challenge.submit', $support->type()->name()), $support->rejectedProof(Surface::CHALLENGE));
    }

    $signedIn = $this->answerChallenge($user, $support);

    $this->assertSignedIn($signedIn, '/');

    $this->switchToFreshBrowser();
    $this->passFirstFactor($user);
    $givenBack = $this->post(route('login.challenge.submit', $support->type()->name()), $support->rejectedProof(Surface::CHALLENGE));
    $spent = $this->post(route('login.challenge.submit', $support->type()->name()), $support->rejectedProof(Surface::CHALLENGE));

    $this->assertThrottled($spent);
    expect($givenBack->getStatusCode())->not->toBe(429);
});

it('cancels the pending sign-in, back at sign-in with the cancelled status', function () {
    $user = $this->createUser();
    $support = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);

    $response = $this->delete(route('login.challenge.cancel'));

    $page = $this->get(route('login'));
    $answer = $this->answerChallenge($user, $support);

    $this->assertChallengeCancelled($response);
    $this->assertSignInPage($page, ['status' => __('keystone::messages.sign-in-cancelled')]);
    $this->assertNoPendingSignIn($answer);
});

it('counts cancels against the change allowance', function () {
    foreach (range(1, $this->requestAllowance(StepKind::CHANGE)) as $i) {
        $this->delete(route('login.challenge.cancel'));
    }

    $response = $this->delete(route('login.challenge.cancel'));

    $this->assertThrottled($response);
});

it('gives the session a new id when it cancels', function () {
    $user = $this->createUser();
    $support = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $cookie = config('session.cookie');
    $held = $this->passFirstFactor($user)->getCookie($cookie)->getValue();

    $this->withCookie($cookie, $held)->delete(route('login.challenge.cancel'));

    $this->switchToFreshBrowser();
    $response = $this->withCookie($cookie, $held)->answerChallenge($user, $support);

    $this->assertNoPendingSignIn($response);
});

it('resumes the same failed-attempt budget after cancelling and starting over', function () {
    $user = $this->createUser();
    $support = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);

    foreach (range(1, $this->failedAttemptAllowance()) as $i) {
        $this->travel(7)->seconds();
        $this->withServerVariables(['REMOTE_ADDR' => "10.1.{$i}.1"])
            ->post(route('login.challenge.submit', $support->type()->name()), $support->rejectedProof(Surface::CHALLENGE));
    }

    $this->delete(route('login.challenge.cancel'));
    $this->passFirstFactor($user);

    $response = $this->withServerVariables(['REMOTE_ADDR' => '10.2.0.1'])->answerChallenge($user, $support);

    $this->assertThrottled($response);
});

it('sends a session without a pending sign-in to sign in', function (Closure $step) {
    $support = $this->supportFor(Surface::CHALLENGE);

    $response = $step($this, $support);

    $this->assertNoPendingSignIn($response);
})->with([
    'the challenge page' => [fn ($test, CredentialTypeSupport $support) => $test->get(route('login.challenge'))],
    'an answer' => [fn ($test, CredentialTypeSupport $support) => $test->post(route('login.challenge.submit', $support->type()->name()), $support->rejectedProof(Surface::CHALLENGE))],
]);

it('ends a pending sign-in 15 minutes after its hold', function () {
    $user = $this->createUser();
    $support = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);

    $this->travel(15)->minutes();
    $response = $this->answerChallenge($user, $support);

    $this->assertNoPendingSignIn($response);
});

it("drops a pending sign-in once its account's credential epoch moved, and records it voided", function (Closure $step) {
    $user = $this->createUser();
    $support = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);

    EndSessions::dispatchSync($user, operator: 'margaret');
    $response = $step($this, $user, $support);

    $retry = $this->answerChallenge($user, $support);

    $this->assertNoPendingSignIn($response);
    $this->assertNoPendingSignIn($retry);
    expect($this->securityEvents()->for($user)->last()->type)->toBe(SecurityEventType::SIGN_IN_VOIDED);
})->with([
    'on the next show' => [fn ($test) => $test->get(route('login.challenge'))],
    'on the next answer' => [fn ($test, KeystoneUser $user, CredentialTypeSupport $support) => $test->answerChallenge($user, $support)],
]);

it('drops a pending sign-in of an account suspended while it was held', function () {
    $user = $this->createUser();
    $support = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);

    SuspendAccount::dispatchSync($user, operator: 'margaret');
    $response = $this->answerChallenge($user, $support);

    $this->assertNoPendingSignIn($response);
    expect($this->securityEvents()->for($user)->last()->type)->toBe(SecurityEventType::SIGN_IN_VOIDED);
});

it('drops the hold once the account no longer holds a second factor', function (Closure $step) {
    $user = $this->createUser();
    $support = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);

    $this->removeCredentials($user, $support);
    $response = $step($this, $user, $support);

    $this->assertNoPendingSignIn($response);
})->with([
    'on the next show' => [fn ($test) => $test->get(route('login.challenge'))],
    'on the next answer' => [fn ($test, KeystoneUser $user, CredentialTypeSupport $support) => $test->answerChallenge($user, $support)],
]);
