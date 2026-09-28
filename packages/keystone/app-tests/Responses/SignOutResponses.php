<?php

namespace ClaudioDekker\Keystone\AppTests\Responses;

use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
class SignOutResponses
{
    /**
     * Assert the response sends the signed-out user to the sign-in page.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertSignedOut(TestResponse $response): void
    {
        $response->assertRedirectToRoute('login');
    }
}
