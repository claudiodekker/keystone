<?php

use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Models\UserEmail;

it('signs a user in with their email address and secret', function () {
    $user = $this->createUser();
    UserEmail::factory()->for($user, 'user')->verified()->create(['address' => 'ada@example.com']);
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $user);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $next = $this->get(route('login'));

    $this->assertSignedIn($response, '/');
    $this->assertAlreadySignedIn($next);
});
