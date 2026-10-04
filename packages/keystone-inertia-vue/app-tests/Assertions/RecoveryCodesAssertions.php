<?php

namespace ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions;

use ClaudioDekker\Keystone\AppTests\Assertions\RecoveryCodesAssertions as KeystoneRecoveryCodesAssertions;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
trait RecoveryCodesAssertions
{
    use KeystoneRecoveryCodesAssertions;

    /**
     * Assert the response is the page showing the staged recovery codes.
     *
     * @param  TestResponse<Response>  $response
     * @param  list<string>  $codes
     */
    public function assertRecoveryCodesPage(TestResponse $response, array $codes): void
    {
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('auth/RecoveryCodes')->where('codes', $codes));
    }
}
