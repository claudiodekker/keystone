<?php

use ClaudioDekker\Keystone\Methods\Surface;

it('passes the first factor of a user without a second factor, signing them in', function () {
    $user = $this->createUser();

    $response = $this->passFirstFactor($user);

    $this->assertSignedIn($response, '/');
});

it('passes the first factor of a user with a second factor, holding them for the challenge', function () {
    $user = $this->createUser();
    $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);

    $response = $this->passFirstFactor($user);

    $this->assertHeldForChallenge($response);
});

it('answers the challenge of a held user with their second factor, signing them in', function () {
    $user = $this->createUser();
    $support = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);

    $response = $this->answerChallenge($user, $support);

    $this->assertSignedIn($response, '/');
});
