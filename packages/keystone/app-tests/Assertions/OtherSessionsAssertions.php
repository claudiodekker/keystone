<?php

namespace ClaudioDekker\Keystone\AppTests\Assertions;

use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
trait OtherSessionsAssertions
{
    /**
     * Assert the response is the page confirming the sign-out of every other session.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertSignOutOthersPage(TestResponse $response): void
    {
        $response->assertOk();
    }

    /**
     * Assert the response sends the user whose other sessions were signed out to the security page.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertOtherSessionsRevoked(TestResponse $response): void
    {
        $response->assertRedirectToRoute('security');
    }
}
