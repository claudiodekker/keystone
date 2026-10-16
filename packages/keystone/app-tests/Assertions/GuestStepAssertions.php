<?php

namespace ClaudioDekker\Keystone\AppTests\Assertions;

use ClaudioDekker\Keystone\AppTests\KeystoneTestResponse;

/**
 * Check how the app answers a signed-in user at a guest step, which every guest step answers alike.
 *
 * @api
 */
trait GuestStepAssertions
{
    /**
     * Assert the guest step refused a signed-in user.
     */
    public function assertAlreadySignedIn(KeystoneTestResponse $response): void
    {
        $response->assertRedirect('/');
    }
}
