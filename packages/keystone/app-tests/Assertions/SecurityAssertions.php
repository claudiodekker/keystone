<?php

namespace ClaudioDekker\Keystone\AppTests\Assertions;

use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
trait SecurityAssertions
{
    /**
     * Assert the response is the security page, listing the credentials with the labels in order.
     *
     * @param  TestResponse<Response>  $response
     * @param  list<string>  $labels
     */
    public function assertSecurityPage(TestResponse $response, array $labels): void
    {
        $response->assertOk()->assertSeeInOrder($labels);
    }

    /**
     * Assert the response is the security page, listing no credential with the label.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertSecurityPageOmits(TestResponse $response, string $label): void
    {
        $response->assertOk()->assertDontSee($label);
    }

    /**
     * Assert the response sends a guest away from the signed-in-only security page.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertGuestSentAwayFromSecurity(TestResponse $response): void
    {
        $response->assertRedirectToRoute('login');
    }
}
