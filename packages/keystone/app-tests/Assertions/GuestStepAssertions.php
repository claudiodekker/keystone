<?php

namespace ClaudioDekker\Keystone\AppTests\Assertions;

use Illuminate\Testing\TestResponse;

/**
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
