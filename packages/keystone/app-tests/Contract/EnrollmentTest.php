<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\AppTests\Assertions\EnrollmentAssertions;
use ClaudioDekker\Keystone\AppTests\Support\CredentialTypeSupport;
use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Support\Facades\Route;

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

it('holds a signed-in session of every installed sign-in type once the app requires enrollment', function () {
    Route::middleware(['web', 'auth'])->get('keystone-app-tests/signed-in-only', fn () => 'Signed in.');

    $this->eachSupportFor(Surface::SIGN_IN, function (CredentialTypeSupport $support) {
        $this->withoutMandates();
        $this->signInAccount($support, "{$support->type()}@example.com");
        $this->withMandates();

        $response = $this->get('keystone-app-tests/signed-in-only');

        $this->assertDemotedToEnrollment($response);
        $this->assertGuest();
    });
});

it('refuses a JSON request from a signed-in session of every installed sign-in type once the app requires enrollment', function () {
    Route::middleware(['web', 'auth'])->get('keystone-app-tests/signed-in-only', fn () => 'Signed in.');

    $this->eachSupportFor(Surface::SIGN_IN, function (CredentialTypeSupport $support) {
        $this->withoutMandates();
        $this->signInAccount($support, "{$support->type()}@example.com");
        $this->withMandates();

        $this->assertDemotedJsonRefused($this->getJson('keystone-app-tests/signed-in-only'));
    });
});
