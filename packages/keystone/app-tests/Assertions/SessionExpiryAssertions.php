<?php

namespace ClaudioDekker\Keystone\AppTests\Assertions;

use Illuminate\Testing\TestResponse;

/**
 * @api
 */
trait SessionExpiryAssertions
{
    /**
     * Assert the response sent the user whose session expired to sign in again.
     */
    public function assertSessionExpired(TestResponse $response): void
    {
        $response->assertRedirectToRoute('login');
    }

    /**
     * Assert the response told a JSON client its session expired.
     */
    public function assertSessionExpiredForJson(TestResponse $response): void
    {
        $response->assertUnauthorized();
        $response->assertJsonPath('reason', 'expired');
        $response->assertJsonPath('message', __('keystone::messages.session-expired'));
    }
}
