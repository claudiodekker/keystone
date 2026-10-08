<?php

namespace ClaudioDekker\Keystone\AppTests\Assertions;

use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
trait CredentialRemovalAssertions
{
    /**
     * Assert the response is the page confirming a credential's removal, naming it by its label, or its type when it has none.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertRemovalPage(TestResponse $response, string $name): void
    {
        $response->assertOk()->assertSee($name);
    }

    /**
     * Assert the response sends the user whose credential was removed to the security page.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertCredentialRemoved(TestResponse $response): void
    {
        $response->assertRedirectToRoute('security');
    }

    /**
     * Assert the response sends the user away from a credential the account doesn't hold, to the security page.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertCredentialNotFound(TestResponse $response): void
    {
        $response->assertRedirectToRoute('security');
    }
}
