<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\AppTests\Assertions\SignInAssertions;
use ClaudioDekker\Keystone\AppTests\Support\CredentialTypeSupport;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Surface;

pest()->extend(AppTestCase::class)->use(AppTestCase::assertions(SignInAssertions::class));

beforeEach(function () {
    $this->withoutMandates();
});

function submitSignIn(AppTestCase $test, CredentialTypeSupport $support, string $identifier, array $proof)
{
    return $test->post(route('login.submit', ['type' => $support->type()]), ['identifier' => $identifier, ...$proof]);
}

function provesTwoFactorsAtTheChallenge(CredentialTypeSupport $support): bool
{
    return app(CredentialTypes::class)->find($support->type(), Surface::CHALLENGE)?->representsMultipleFactors() ?? false;
}

it('signs in with a valid proof of every installed type', function () {
    $this->eachSupportFor(Surface::SIGN_IN, function (CredentialTypeSupport $support) {
        $account = $this->createAccount("{$support->type()}@example.com");
        $this->arrangeCredential($account, $support, Surface::SIGN_IN);

        $response = submitSignIn($this, $support, "{$support->type()}@example.com", $support->validProof(Surface::SIGN_IN));

        $this->assertSignedIn($response, '/');
        $this->assertAuthenticatedAs($account);
    });
});

it('refuses a rejected proof of every installed type', function () {
    $this->eachSupportFor(Surface::SIGN_IN, function (CredentialTypeSupport $support) {
        $account = $this->createAccount("{$support->type()}@example.com");
        $this->arrangeCredential($account, $support, Surface::SIGN_IN);

        $response = submitSignIn($this, $support, "{$support->type()}@example.com", $support->rejectedProof(Surface::SIGN_IN));

        $this->assertSignInRefused($response);
        $this->assertGuest();
    });
});

it('refuses a proof for no account exactly like a rejected one, for every installed type', function () {
    $this->eachSupportFor(Surface::SIGN_IN, function (CredentialTypeSupport $support) {
        $account = $this->createAccount("{$support->type()}@example.com");
        $this->arrangeCredential($account, $support, Surface::SIGN_IN);

        $this->assertIndistinguishable(
            fn () => submitSignIn($this, $support, "{$support->type()}@example.com", $support->rejectedProof(Surface::SIGN_IN)),
            fn () => submitSignIn($this, $support, 'nobody@example.com', $support->validProof(Surface::SIGN_IN)),
        );
    });
});

it('holds a valid proof of every installed type for the enrollment the app requires', function (bool $secondFactor, bool $recoveryCodes) {
    config(['keystone.require_second_factor' => $secondFactor, 'keystone.require_recovery_codes' => $recoveryCodes]);

    $this->eachSupportFor(Surface::SIGN_IN, function (CredentialTypeSupport $support) {
        $account = $this->createAccount("{$support->type()}@example.com");
        $this->arrangeCredential($account, $support, Surface::SIGN_IN);

        $response = submitSignIn($this, $support, "{$support->type()}@example.com", $support->validProof(Surface::SIGN_IN));

        $this->assertEnrollmentOwed($response);
        $this->assertGuest();
    });
})->with([
    'both required' => [true, true],
    'only recovery codes required' => [false, true],
]);

it('signs in or holds a valid proof of every installed type for a second factor, as the type proves one factor or two', function () {
    config(['keystone.require_second_factor' => true, 'keystone.require_recovery_codes' => false]);

    $this->eachSupportFor(Surface::SIGN_IN, function (CredentialTypeSupport $support) {
        $account = $this->createAccount("{$support->type()}@example.com");
        $this->arrangeCredential($account, $support, Surface::SIGN_IN);

        $response = submitSignIn($this, $support, "{$support->type()}@example.com", $support->validProof(Surface::SIGN_IN));

        if (provesTwoFactorsAtTheChallenge($support)) {
            $this->assertSignedIn($response, '/');
            $this->assertAuthenticatedAs($account);

            return;
        }

        $this->assertEnrollmentOwed($response);
        $this->assertGuest();
    });
});
