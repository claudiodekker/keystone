<?php

namespace Tests\Keystone\Assertions;

use ClaudioDekker\Keystone\AppTests\Assertions\GuestStepAssertions as KeystoneGuestStepAssertions;

/**
 * Check how this app answers a signed-in user at a guest step. Redefine a method here when the app changes that answer.
 */
trait GuestStepAssertions
{
    use KeystoneGuestStepAssertions;
}
