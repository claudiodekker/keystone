<?php

namespace ClaudioDekker\Keystone\AppTests\Assertions;

use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
trait SignOutAssertions
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

    /**
     * Assert the response sends a guest away from the signed-in-only sign-out step.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertGuestSentAway(TestResponse $response): void
    {
        $response->assertRedirectToRoute('login');
    }

    /**
     * Assert the response refuses the sign-out because its rate limit is spent, saying when to try again.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertSignOutThrottled(TestResponse $response): void
    {
        $seconds = $response->headers->get('Retry-After');

        $response->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertSee(__('keystone::messages.throttled', ['seconds' => $seconds]));
    }
}
