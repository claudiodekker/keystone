<?php

namespace ClaudioDekker\Keystone\AppTests\Assertions;

use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
trait SudoAssertions
{
    /**
     * Assert the response sends the user whose sudo ended back to the page they came from, which is the root when they came from none.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertSudoEnded(TestResponse $response): void
    {
        $response->assertRedirect('/');
    }

    /**
     * Assert the response sends a guest away from the signed-in-only sudo steps.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertGuestSentAway(TestResponse $response): void
    {
        $response->assertRedirectToRoute('login');
    }

    /**
     * Assert the response refuses the sudo step because its rate limit is spent, saying when to try again.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertSudoThrottled(TestResponse $response): void
    {
        $seconds = $response->headers->get('Retry-After');

        $response->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertSee(__('keystone::messages.throttled', ['seconds' => $seconds]));
    }
}
