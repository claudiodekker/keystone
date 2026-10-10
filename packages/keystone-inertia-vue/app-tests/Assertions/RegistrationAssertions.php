<?php

namespace ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions;

use ClaudioDekker\Keystone\AppTests\Assertions\RegistrationAssertions as KeystoneRegistrationAssertions;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
trait RegistrationAssertions
{
    use KeystoneRegistrationAssertions;

    /**
     * Assert the response is the register page.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertRegistrationPage(TestResponse $response): void
    {
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('auth/Register'));
    }

    /**
     * Assert the response is the step telling the user to check their inbox.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertRegistrationLinkSentPage(TestResponse $response): void
    {
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('auth/RegisterLinkSent'));
    }
}
