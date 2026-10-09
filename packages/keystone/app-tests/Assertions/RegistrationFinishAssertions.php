<?php

namespace ClaudioDekker\Keystone\AppTests\Assertions;

use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
trait RegistrationFinishAssertions
{
    /**
     * Assert the response is the page that finishes registering the address.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertRegistrationFinishPage(TestResponse $response, string $address): void
    {
        $response->assertOk()->assertSee($address);
    }

    /**
     * Assert the response sends a session that holds no proven address back to register.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertSentBackToRegister(TestResponse $response): void
    {
        $response->assertRedirectToRoute('register');
    }
}
