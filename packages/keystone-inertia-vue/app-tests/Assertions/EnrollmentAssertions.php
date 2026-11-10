<?php

namespace ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions;

use ClaudioDekker\Keystone\AppTests\Assertions\EnrollmentAssertions as KeystoneEnrollmentAssertions;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * @api
 */
trait EnrollmentAssertions
{
    use KeystoneEnrollmentAssertions;

    /**
     * Assert the response shows the second-factor step, the types and the preselected type each a prop of its own, with the given fields.
     *
     * @param  array<string, mixed>  $fields
     */
    public function assertEnrollmentPage(TestResponse $response, array $fields = []): void
    {
        $response->assertOk();

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/Enrollment')
            ->hasAll(['preselected', 'types'])
            ->whereAll($fields));

        PHPUnit::assertArrayHasKey('encryptHistory', $response->inertiaPage(), 'The browser history is not encrypted.');
    }

    /**
     * Get what the second-factor step shows of the type's started ceremony, from its types prop.
     *
     * @return array<string, mixed>
     */
    public function shownCeremony(TestResponse $response, string $type): array
    {
        $this->assertEnrollmentPage($response);

        $shown = collect($response->inertiaProps('types'))->firstWhere('name', $type);

        PHPUnit::assertIsArray($shown['ceremony'] ?? null, "The second-factor step shows no ceremony of [{$type}].");

        return $shown['ceremony'];
    }

    /**
     * Assert the response shows the recovery-code step, with the codes as a prop.
     */
    public function assertRecoveryCodesPage(TestResponse $response): void
    {
        $response->assertOk();

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/RecoveryCodes')
            ->has('codes'));

        PHPUnit::assertArrayHasKey('encryptHistory', $response->inertiaPage(), 'The browser history is not encrypted.');
    }

    /**
     * Get the recovery codes the recovery-code step shows, from its codes prop.
     *
     * @return list<string>
     */
    public function shownRecoveryCodes(TestResponse $response): array
    {
        $this->assertRecoveryCodesPage($response);

        $codes = $response->inertiaProps('codes');

        PHPUnit::assertIsList($codes, 'The recovery-code step shows no list of codes.');

        return $codes;
    }
}
