<?php

namespace ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions;

use ClaudioDekker\Keystone\AppTests\Assertions\CredentialRemovalAssertions as KeystoneCredentialRemovalAssertions;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
trait CredentialRemovalAssertions
{
    use KeystoneCredentialRemovalAssertions;

    /**
     * Assert the response is the page confirming a credential's removal, naming it by its label, or its type when it has none.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertRemovalPage(TestResponse $response, string $name): void
    {
        $response->assertInertia(function (AssertableInertia $page) use ($name) {
            $props = $page->component('settings/CredentialRemoval')->toArray()['props'];

            Assert::assertSame($name, $props['label'] ?? $props['type'], 'The page names another credential.');
        });
    }
}
