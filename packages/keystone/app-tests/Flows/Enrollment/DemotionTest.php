<?php

use ClaudioDekker\Keystone\Contracts\SessionDemotedResponse;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

it('ends a signed-in session once the account no longer meets a mandate, sending the user to sign in with its status', function () {
    $this->withoutEnrollmentMandates();
    $user = $this->createUser();
    $this->passFirstFactor($user);

    config(['keystone.require_second_factor' => true]);
    Auth::forgetGuards();
    $response = $this->get($this->protectedPage());

    $page = $this->get(route('login'));

    $this->assertSessionDemoted($response);
    $this->assertSignInPage($page, ['status' => __('keystone::messages.session-demoted')]);
});

it('tells a JSON client its session was demoted', function () {
    $this->withoutEnrollmentMandates();
    $user = $this->createUser();
    $this->passFirstFactor($user);

    config(['keystone.require_recovery_codes' => true]);
    Auth::forgetGuards();
    $response = $this->getJson($this->protectedPage());

    $this->assertSessionDemotedForJson($response);
});

it('records session.ended with the reason demoted and clears the browser\'s site data', function () {
    $this->withoutEnrollmentMandates();
    $user = $this->createUser();
    $this->passFirstFactor($user);

    config(['keystone.require_second_factor' => true]);
    Auth::forgetGuards();
    $response = $this->get(route('login'));

    $ended = $this->securityEvents()->for($user)->last();

    $this->assertSiteDataCleared($response, ['cache', 'storage']);
    expect($ended->type)->toBe(SecurityEventType::SESSION_ENDED)
        ->and($ended->reason)->toBe('keystone.demoted');
});

it('lands on the page it asked for once the user signed in again and enrolled', function () {
    $this->withoutEnrollmentMandates();
    $user = $this->createUser();
    $this->passFirstFactor($user);
    $protected = $this->protectedPage();

    config(['keystone.require_recovery_codes' => true]);
    Auth::forgetGuards();
    $this->get($protected);
    $this->passFirstFactor($user);
    $codes = $this->shownRecoveryCodes($this->get(route('login.enrollment')));

    $response = $this->post(route('login.enrollment.submit', 'recovery-code'), ['recovery_code' => $codes[3]]);

    $this->assertSignedIn($response, $protected);
});

it('keeps a signed-in session whose account meets every mandate', function () {
    $user = $this->createUser();
    $support = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);
    $this->answerChallenge($user, $support);
    $codes = $this->shownRecoveryCodes($this->get(route('login.enrollment')));
    $this->post(route('login.enrollment.submit', 'recovery-code'), ['recovery_code' => $codes[0]]);

    Auth::forgetGuards();
    $response = $this->get(route('login'));

    $this->assertAlreadySignedIn($response);
});

it("answers with the app's own demotion response", function () {
    $this->withoutEnrollmentMandates();
    $user = $this->createUser();
    $this->passFirstFactor($user);
    $this->app->instance(SessionDemotedResponse::class, new class implements SessionDemotedResponse
    {
        public function toResponse(Request $request, AuthenticationException $exception): Response
        {
            return new Response('demoted', 418);
        }
    });

    config(['keystone.require_second_factor' => true]);
    Auth::forgetGuards();
    $response = $this->get($this->protectedPage());

    expect($response->getStatusCode())->toBe(418);
});
