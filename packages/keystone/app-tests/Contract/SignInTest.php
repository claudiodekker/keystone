<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\AppTests\Assertions\SignInAssertions;
use ClaudioDekker\Keystone\AppTests\Support\CredentialTypeSupport;
use ClaudioDekker\Keystone\Methods\Surface;

pest()->extend(AppTestCase::class)->use(AppTestCase::assertions(SignInAssertions::class));

function submitSignIn(AppTestCase $test, CredentialTypeSupport $support, string $identifier, array $proof)
{
    return $test->post(route('login.submit', ['type' => $support->type()]), ['identifier' => $identifier, ...$proof]);
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
