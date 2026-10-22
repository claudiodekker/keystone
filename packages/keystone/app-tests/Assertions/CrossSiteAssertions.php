<?php

namespace ClaudioDekker\Keystone\AppTests\Assertions;

use Illuminate\Testing\TestResponse;

/**
 * Check the refusal a cross-site request to a Keystone route gets, which the app's exception handler renders.
 *
 * @api
 */
trait CrossSiteAssertions
{
    /**
     * Assert the response refused the request as Laravel refuses a CSRF mismatch.
     */
    public function assertCrossSiteRefused(TestResponse $response): void
    {
        $response->assertStatus(419);
    }
}
