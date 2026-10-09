<?php

namespace ClaudioDekker\Keystone\AppTests\Assertions;

use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
trait SessionRevocationAssertions
{
    /**
     * Assert the response is the page confirming a session's sign-out.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertRevocationPage(TestResponse $response): void
    {
        $response->assertOk();
    }

    /**
     * Assert the response refuses the sign-out, sending the user to the security page with the message.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertRevocationRefused(TestResponse $response, string $message): void
    {
        $response->assertRedirectToRoute('security')->assertSessionHasErrors(['session' => $message]);
    }

    /**
     * Assert the response sends the user whose session was signed out to the security page.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertSessionRevoked(TestResponse $response): void
    {
        $response->assertRedirectToRoute('security');
    }

    /**
     * Assert the response sends the user away from a session the account doesn't have, to the security page.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertSessionNotFound(TestResponse $response): void
    {
        $response->assertRedirectToRoute('security');
    }

    /**
     * Assert the response sends the user away from a session driver that can't list sessions, to the security page.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertSessionsUnavailable(TestResponse $response): void
    {
        $response->assertRedirectToRoute('security');
    }
}
