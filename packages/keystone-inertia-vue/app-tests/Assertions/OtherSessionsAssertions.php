<?php

namespace ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions;

use ClaudioDekker\Keystone\AppTests\Assertions\OtherSessionsAssertions as KeystoneOtherSessionsAssertions;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
trait OtherSessionsAssertions
{
    use KeystoneOtherSessionsAssertions;

    /**
     * Assert the response is the page confirming the sign-out of every other session.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertSignOutOthersPage(TestResponse $response): void
    {
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('settings/SignOutOthers'));
    }
}
