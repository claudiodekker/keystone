<?php

namespace ClaudioDekker\Keystone\AppTests\Assertions;

use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
trait SessionExpiryAssertions
{
    /**
     * Assert the response tells a browser whose session outlived its absolute lifetime to sign in again.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertExpiredSessionRefused(TestResponse $response): void
    {
        $response->assertRedirectToRoute('login');
    }
}
