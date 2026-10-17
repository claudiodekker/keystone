<?php

namespace ClaudioDekker\Keystone\AppTests\Assertions;

use Illuminate\Testing\TestResponse;

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
    public function assertAlreadySignedIn(TestResponse $response): void
    {
        $response->assertRedirect('/');
    }
}
