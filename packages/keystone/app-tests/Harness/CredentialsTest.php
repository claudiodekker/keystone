<?php

use ClaudioDekker\Keystone\Methods\Surface;

it("removes the user's credentials of the supported type, and leaves another user's alone", function () {
    $this->withoutEnrollmentMandates();
    $ada = $this->createUser();
    $grace = $this->createUser();
    $support = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $ada);
    $support->arrangeCredential(Surface::CHALLENGE, $grace);

    $this->removeCredentials($ada, $support);

    $removed = $this->passFirstFactor($ada);
    $this->switchToFreshBrowser();
    $kept = $this->passFirstFactor($grace);

    $this->assertSignedIn($removed, '/');
    $this->assertHeldForChallenge($kept);
});
