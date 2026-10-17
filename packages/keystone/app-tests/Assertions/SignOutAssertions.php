<?php

namespace ClaudioDekker\Keystone\AppTests\Assertions;

use ClaudioDekker\Keystone\AppTests\KeystoneTestResponse;

/**
 * Check how the app answers signing out, one method per response hook of the sign-out controller.
 *
 * @api
 */
trait SignOutAssertions
{
    /**
     * Assert the response sent the signed-out user to sign in again.
     */
    public function assertSignedOut(KeystoneTestResponse $response): void
    {
        $response->assertRedirectToRoute('login');
    }
}
