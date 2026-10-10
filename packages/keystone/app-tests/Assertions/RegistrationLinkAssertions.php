<?php

namespace ClaudioDekker\Keystone\AppTests\Assertions;

use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
trait RegistrationLinkAssertions
{
    /**
     * Assert the response is the mailed registration link's step, whose one button spends it.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertRegistrationLinkPage(TestResponse $response): void
    {
        $response->assertOk();
    }

    /**
     * Assert the response sends the user whose link was spent on to finish registering.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertRegistrationLinkConsumed(TestResponse $response): void
    {
        $response->assertRedirectToRoute('register.finish');
    }

    /**
     * Assert the response sends the user whose link no longer works on to "link expired".
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertRegistrationLinkExpired(TestResponse $response): void
    {
        $response->assertRedirectToRoute('register.link-expired');
    }

    /**
     * Assert the response is the step saying the link no longer works.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertRegistrationLinkExpiredPage(TestResponse $response): void
    {
        $response->assertOk();
    }
}
