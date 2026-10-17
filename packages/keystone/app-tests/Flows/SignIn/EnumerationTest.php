<?php

use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Models\UserEmail;

it('answers an unknown address exactly like a wrong secret', function () {
    $user = $this->createUser();
    UserEmail::factory()->for($user, 'user')->verified()->create(['address' => 'ada@example.com']);
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $user);

    $wrongSecret = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    $unknown = $this->freshBrowser()->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'grace@example.com',
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    $this->assertIndistinguishable($wrongSecret, $unknown);
})->group('SI-3', 'SI-11');

it('answers an address two accounts count as verified exactly like a wrong secret', function () {
    $user = $this->createUser();
    UserEmail::factory()->for($user, 'user')->verified()->create(['address' => 'ada@example.com']);
    UserEmail::factory()->count(2)->create(['address' => 'shared@example.com']);
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $user);

    $wrongSecret = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    $unresolved = $this->freshBrowser()->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'shared@example.com',
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    $this->assertIndistinguishable($wrongSecret, $unresolved);
})->group('SI-3', 'SI-8', 'SI-11');

it('takes at least 300 ms to refuse', function (string $identifier) {
    $user = $this->createUser();
    UserEmail::factory()->for($user, 'user')->verified()->create(['address' => 'ada@example.com']);
    UserEmail::factory()->count(2)->create(['address' => 'shared@example.com']);
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $user);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => $identifier,
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    $this->assertTookAtLeast($response, 300);
})->with([
    'a wrong secret' => 'ada@example.com',
    'an unknown address' => 'grace@example.com',
    'an unresolved address' => 'shared@example.com',
])->group('SI-3');

it('answers an identifier that is not valid UTF-8 exactly like a wrong secret', function () {
    $user = $this->createUser();
    UserEmail::factory()->for($user, 'user')->verified()->create(['address' => 'ada@example.com']);
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $user);

    $wrongSecret = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    $malformed = $this->freshBrowser()->post(route('login.submit', $support->type()->name()), [
        'identifier' => "ada\xFF@example.com",
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    $this->assertIndistinguishable($wrongSecret, $malformed);
})->group('SI-3', 'SI-11');
