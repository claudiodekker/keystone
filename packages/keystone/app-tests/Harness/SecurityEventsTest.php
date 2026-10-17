<?php

use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;

it('reads the events for the user, and none for another user', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $other = $this->createUser();
    $this->addVerifiedAddress($other, 'grace@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user)->arrangeCredential(Surface::SIGN_IN, $other);

    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'grace@example.com',
        ...$support->validProof(Surface::SIGN_IN, $other),
    ]);

    $this->flushSession()->post(route('logout'));
    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $events = $this->securityEvents()->for($user);

    expect($events->pluck('user_id')->all())->toBe([$user->getAuthIdentifier()])
        ->and($events->pluck('type')->all())->toBe([SecurityEventType::SIGNED_IN]);
});

it('reads every event, oldest first, when given no user', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $other = $this->createUser();
    $this->addVerifiedAddress($other, 'grace@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user)->arrangeCredential(Surface::SIGN_IN, $other);

    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'grace@example.com',
        ...$support->validProof(Surface::SIGN_IN, $other),
    ]);

    $this->travel(5)->seconds();
    $this->flushSession()->post(route('logout'));
    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    expect($this->securityEvents()->stored()->pluck('user_id')->all())->toBe([$other->getAuthIdentifier(), $user->getAuthIdentifier()]);
});
