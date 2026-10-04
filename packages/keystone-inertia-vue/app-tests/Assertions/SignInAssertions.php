<?php

namespace ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions;

use ClaudioDekker\Keystone\AppTests\Assertions\SignInAssertions as KeystoneSignInAssertions;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
trait SignInAssertions
{
    use KeystoneSignInAssertions;

    /**
     * Assert the response is the sign-in page, showing the status message when one is given and none otherwise.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertSignInPage(TestResponse $response, ?string $status = null): void
    {
        $response->assertInertia(function (AssertableInertia $page) use ($status) {
            $page->component('auth/Login')->has('types')->where('status', $status);
        });
    }
}
