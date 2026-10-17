<?php

use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;

it('records signed_in for the account when a user signs in', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignedIn($response, '/');

    $events = $this->securityEvents()->for($user);

    expect($events)->toHaveCount(1)
        ->and($events[0]->type)->toBe(SecurityEventType::SIGNED_IN)
        ->and($events[0]->user_id)->toBe($user->getAuthIdentifier());
});

it('records proof.rejected for the account when its sign-in is refused', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    $this->assertSignInRefused($response, __('keystone::messages.credentials-rejected'));

    $events = $this->securityEvents()->for($user);

    expect($events)->toHaveCount(1)
        ->and($events[0]->type)->toBe(SecurityEventType::PROOF_REJECTED)
        ->and($events[0]->credential_type)->toBe($support->type()->name())
        ->and($events[0]->credential_id)->toBeNull();
});
