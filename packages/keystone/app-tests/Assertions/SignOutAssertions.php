<?php

namespace ClaudioDekker\Keystone\AppTests\Assertions;

use Illuminate\Testing\TestResponse;

/**
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

    /**
     * Assert the response refused signing out as throttled.
     */
    public function assertSignOutThrottled(TestResponse $response): void
    {
        $this->assertThrottled($response);
    }
}
