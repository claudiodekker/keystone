<?php

namespace ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions;

use ClaudioDekker\Keystone\AppTests\Assertions\SecurityAssertions as KeystoneSecurityAssertions;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
trait SecurityAssertions
{
    use KeystoneSecurityAssertions;

    /**
     * Assert the response is the security page, listing exactly the credentials with the labels in order, live ones before leftovers.
     *
     * @param  TestResponse<Response>  $response
     * @param  list<string>  $labels
     */
    public function assertSecurityPage(TestResponse $response, array $labels): void
    {
        Assert::assertSame($labels, $this->securityPageLabels($response), 'The security page lists other credentials.');
    }

    /**
     * Assert the response is the security page, listing no credential with the label.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertSecurityPageOmits(TestResponse $response, string $label): void
    {
        Assert::assertNotContains($label, $this->securityPageLabels($response), 'The security page lists the credential.');
    }

    /**
     * Get the labels of every credential the security page lists, live ones before leftovers.
     *
     * @param  TestResponse<Response>  $response
     * @return list<?string>
     */
    protected function securityPageLabels(TestResponse $response): array
    {
        $labels = [];

        $response->assertInertia(function (AssertableInertia $page) use (&$labels) {
            $props = $page->component('settings/Security')->toArray()['props'];

            $labels = collect($props['types'])
                ->flatMap(fn (array $type) => $type['credentials'])
                ->concat($props['leftovers'])
                ->pluck('label')
                ->all();
        });

        return $labels;
    }
}
