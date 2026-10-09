<?php

namespace ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions;

use ClaudioDekker\Keystone\AppTests\Assertions\SessionRevocationAssertions as KeystoneSessionRevocationAssertions;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
trait SessionRevocationAssertions
{
    use KeystoneSessionRevocationAssertions;

    /**
     * Assert the response is the page confirming a session's sign-out.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertRevocationPage(TestResponse $response): void
    {
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('settings/SessionRevocation'));
    }
}
