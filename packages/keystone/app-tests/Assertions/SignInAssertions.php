<?php

namespace ClaudioDekker\Keystone\AppTests\Assertions;

use ClaudioDekker\Keystone\AppTests\KeystoneTestResponse;

/**
 * Check how the app answers each outcome of signing in, one method per response hook of the sign-in controller.
 *
 * @api
 */
trait SignInAssertions
{
    /**
     * Assert the response sent the signed-in user on to the given path.
     */
    public function assertSignedIn(KeystoneTestResponse $response, string $redirectTo): void
    {
        $response->assertRedirect($redirectTo);
    }
}
