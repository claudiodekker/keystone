<?php

namespace ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions;

use ClaudioDekker\Keystone\AppTests\Assertions\RegistrationLinkAssertions as KeystoneRegistrationLinkAssertions;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
trait RegistrationLinkAssertions
{
    use KeystoneRegistrationLinkAssertions;

    /**
     * Assert the response is the mailed registration link's step, whose one button spends it.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertRegistrationLinkPage(TestResponse $response): void
    {
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('auth/EmailedLink')->has('action'));
    }

    /**
     * Assert the response is the step saying the link no longer works.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertRegistrationLinkExpiredPage(TestResponse $response): void
    {
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('auth/RegisterLinkExpired'));
    }
}
