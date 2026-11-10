<?php

use ClaudioDekker\Keystone\Methods\Surface;

beforeEach(function () {
    if ($this->supportsFor(Surface::CHALLENGE) === []) {
        $this->markTestSkipped('No installed credential type with test support serves the challenge.');
    }
});

it('signs the held user in with a valid answer of every installed type that serves the challenge', function () {
    foreach ($this->supportsFor(Surface::CHALLENGE) as $support) {
        $user = $this->createUser();
        $support->arrangeCredential(Surface::CHALLENGE, $user);

        $this->switchToFreshBrowser();
        $this->passFirstFactor($user);
        $response = $this->answerChallenge($user, $support);

        $this->assertSignedIn($response, '/');
    }
});

it('refuses a rejected answer of every installed type that serves the challenge, keeping the pending sign-in', function () {
    foreach ($this->supportsFor(Surface::CHALLENGE) as $support) {
        $user = $this->createUser();
        $support->arrangeCredential(Surface::CHALLENGE, $user);
        $wrong = $support->rejectedProof(Surface::CHALLENGE);

        $this->switchToFreshBrowser();
        $this->passFirstFactor($user);
        $response = $this->post(route('login.challenge.submit', $support->type()->name()), $wrong);

        $this->assertChallengeRefused($response, array_key_first($wrong), __('keystone::messages.credential-invalid'));
        $this->assertChallengePage($this->get(route('login.challenge')));
    }
});
