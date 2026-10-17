<?php

namespace ClaudioDekker\Keystone\AppTests\Assertions;

use Illuminate\Testing\TestResponse;

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
    public function assertSignedOut(TestResponse $response): void
    {
        $response->assertRedirectToRoute('login');
    }
}
