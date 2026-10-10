<?php

namespace ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions;

use ClaudioDekker\Keystone\AppTests\Assertions\RegistrationFinishAssertions as KeystoneRegistrationFinishAssertions;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
trait RegistrationFinishAssertions
{
    use KeystoneRegistrationFinishAssertions;

    /**
     * Assert the response is the page that finishes registering the address.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertRegistrationFinishPage(TestResponse $response, string $address): void
    {
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('auth/RegisterFinish')->where('address', $address));
    }
}
