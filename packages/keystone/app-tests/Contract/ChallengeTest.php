<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\AppTests\Assertions\ChallengeAssertions;
use ClaudioDekker\Keystone\AppTests\Support\CredentialTypeSupport;
use ClaudioDekker\Keystone\Methods\Surface;

pest()->extend(AppTestCase::class)->use(AppTestCase::assertions(ChallengeAssertions::class));

beforeEach(function () {
    $this->withoutMandates();
});

it('completes the sign-in with a valid answer of every installed type', function () {
    $this->eachSupportFor(Surface::CHALLENGE, function (CredentialTypeSupport $support) {
        $account = $this->createChallengedAccount($support, "{$support->type()}@example.com");
        $this->passFirstFactor("{$support->type()}@example.com");

        $response = $this->post(route('login.challenge.submit', ['type' => $support->type()]), $support->validProof(Surface::CHALLENGE));

        $this->assertChallengePassed($response, '/');
        $this->assertAuthenticatedAs($account);
    });
});

it('refuses a wrong answer of every installed type', function () {
    $this->eachSupportFor(Surface::CHALLENGE, function (CredentialTypeSupport $support) {
        $this->createChallengedAccount($support, "{$support->type()}@example.com");
        $this->passFirstFactor("{$support->type()}@example.com");

        $response = $this->post(route('login.challenge.submit', ['type' => $support->type()]), $support->rejectedProof(Surface::CHALLENGE));

        $this->assertChallengeRefused($response, $support->type());
        $this->assertGuest();
    });
});

it('moves a valid answer of every installed type on to the recovery codes the app requires', function () {
    $this->withMandates();

    $this->eachSupportFor(Surface::CHALLENGE, function (CredentialTypeSupport $support) {
        $this->createChallengedAccount($support, "{$support->type()}@example.com");
        $this->passFirstFactor("{$support->type()}@example.com");

        $response = $this->post(route('login.challenge.submit', ['type' => $support->type()]), $support->validProof(Surface::CHALLENGE));

        $this->assertEnrollmentOwedAfterChallenge($response);
        $this->assertGuest();
    });
});

it('completes the sign-in with a valid answer of every installed type once the account holds the recovery codes the app requires', function () {
    $this->withMandates();

    $this->eachSupportFor(Surface::CHALLENGE, function (CredentialTypeSupport $support) {
        $account = $this->createChallengedAccount($support, "{$support->type()}@example.com");
        $this->arrangeRecoveryCodes($account);
        $this->passFirstFactor("{$support->type()}@example.com");

        $response = $this->post(route('login.challenge.submit', ['type' => $support->type()]), $support->validProof(Surface::CHALLENGE));

        $this->assertChallengePassed($response, '/');
        $this->assertAuthenticatedAs($account);
    });
});
