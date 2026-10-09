<?php

namespace ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions;

use ClaudioDekker\Keystone\AppTests\Assertions\RecoveryCodeRegenerationAssertions as KeystoneRecoveryCodeRegenerationAssertions;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
trait RecoveryCodeRegenerationAssertions
{
    use KeystoneRecoveryCodeRegenerationAssertions;

    /**
     * Assert the response is the page showing the staged replacement recovery codes in the security settings.
     *
     * @param  TestResponse<Response>  $response
     * @param  list<string>  $codes
     */
    public function assertRecoveryCodeRegenerationPage(TestResponse $response, array $codes): void
    {
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('settings/RegenerateRecoveryCodes')->where('codes', $codes));
    }
}
