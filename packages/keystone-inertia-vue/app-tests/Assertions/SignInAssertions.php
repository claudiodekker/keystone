<?php

namespace ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions;

use ClaudioDekker\Keystone\AppTests\Assertions\SignInAssertions as KeystoneSignInAssertions;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Assert as PHPUnit;

/**
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

        PHPUnit::assertArrayHasKey('encryptHistory', $response->inertiaPage(), 'The browser history is not encrypted.');
    }
}
