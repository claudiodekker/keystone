<?php

namespace ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions;

use ClaudioDekker\Keystone\AppTests\Assertions\SignInAssertions as KeystoneSignInAssertions;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

/**
 * Check how an Inertia app answers each outcome of signing in. Only the sign-in page differs from Keystone's default.
 *
 * @api
 */
trait SignInAssertions
{
    use KeystoneSignInAssertions;

    /**
     * Assert the response shows the sign-in page, the status and the types each a prop of its own, with the given fields.
     *
     * @param  array<string, mixed>  $fields
     */
    public function assertSignInPage(TestResponse $response, array $fields = []): void
    {
        $response->assertOk();

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/Login')
            ->hasAll(['status', 'types'])
            ->whereAll($fields));
    }
}
