<?php

namespace ClaudioDekker\Keystone\AppTests\Assertions;

use ClaudioDekker\Keystone\Status;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
trait RegistrationAssertions
{
    /**
     * Assert the response is the register page.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertRegistrationPage(TestResponse $response): void
    {
        $response->assertOk();
    }

    /**
     * Assert the response sends the user on to the step telling them to check their inbox.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertRegistrationLinkSent(TestResponse $response): void
    {
        $response->assertRedirectToRoute('register.link-sent');
    }

    /**
     * Assert the response sends the user on to finish registering the address, with no link mailed.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertRegistrationStarted(TestResponse $response): void
    {
        $response->assertRedirectToRoute('register.finish');
    }

    /**
     * Assert the response is the step telling the user to check their inbox.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertRegistrationLinkSentPage(TestResponse $response): void
    {
        $response->assertOk();
    }

    /**
     * Assert the response refuses invalid input back to the register page, with an error for each field.
     *
     * @param  TestResponse<Response>  $response
     * @param  list<string>  $fields
     */
    public function assertRegistrationInvalid(TestResponse $response, array $fields): void
    {
        $response->assertRedirectToRoute('register')->assertSessionHasErrors($fields);
    }

    /**
     * Assert the response refuses a registration step because no listed credential type serves registration.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertRegistrationUnavailable(TestResponse $response): void
    {
        $response->assertRedirectToRoute('login')
            ->assertSessionHas(Status::SESSION_KEY, Status::REGISTRATION_UNAVAILABLE->value);
    }

    /**
     * Assert the response sends a signed-in user away from the guest-only registration steps.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertSentAwayFromRegistration(TestResponse $response): void
    {
        $response->assertRedirect('/');
    }
}
