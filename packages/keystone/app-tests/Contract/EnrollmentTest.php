<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\AppTests\Assertions\EnrollmentAssertions;
use ClaudioDekker\Keystone\AppTests\Support\CredentialTypeSupport;

pest()->extend(AppTestCase::class)->use(AppTestCase::assertions(EnrollmentAssertions::class));

beforeEach(function () {
    $this->withMandates();
});

it('enrolls a valid answer of every installed type, storing the credential', function () {
    $this->eachEnrollmentSupport(function (CredentialTypeSupport $support) {
        $account = $this->createFirstFactorAccount("{$support->type()}@example.com");
        $this->passFirstFactor("{$support->type()}@example.com");

        $response = $this->enrollSecondFactor($support);

        $this->assertRecoveryCodesOwed($response);
        $this->assertDatabaseHas('user_credentials', ['user_id' => $account->getKey(), 'type' => $support->type()]);
    });
});

it('refuses a wrong answer of every installed type, storing nothing', function () {
    $this->eachEnrollmentSupport(function (CredentialTypeSupport $support) {
        $account = $this->createFirstFactorAccount("{$support->type()}@example.com");
        $this->passFirstFactor("{$support->type()}@example.com");
        $this->get(route('login.enrollment.start', ['type' => $support->type()]));

        $response = $this->post(route('login.enrollment.submit', ['type' => $support->type()]), $support->rejectedEnrollment($this->enrollmentCeremony($support->type())));

        $this->assertEnrollmentRefused($response, $support->type());
        $this->assertDatabaseMissing('user_credentials', ['user_id' => $account->getKey(), 'type' => $support->type()]);
    });
});
