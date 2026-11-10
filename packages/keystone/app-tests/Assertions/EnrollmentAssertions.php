<?php

namespace ClaudioDekker\Keystone\AppTests\Assertions;

use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;

/**
 * @api
 */
trait EnrollmentAssertions
{
    /**
     * Assert the response shows the second-factor step with the given page fields.
     *
     * @param  array<string, mixed>  $fields
     */
    public function assertEnrollmentPage(TestResponse $response, array $fields = []): void
    {
        $response->assertOk();
        $response->assertViewHas('types');

        foreach ($fields as $name => $value) {
            $response->assertViewHas($name, $value);
        }
    }

    /**
     * Get what the second-factor step shows of the type's started ceremony, asserting it shows that step.
     *
     * @return array<string, mixed>
     */
    public function shownCeremony(TestResponse $response, string $type): array
    {
        $this->assertEnrollmentPage($response);

        $shown = collect($response->viewData('types'))->firstWhere('name', $type);

        Assert::assertIsArray($shown['ceremony'] ?? null, "The second-factor step shows no ceremony of [{$type}].");

        return $shown['ceremony'];
    }

    /**
     * Assert the response shows the recovery-code step.
     */
    public function assertRecoveryCodesPage(TestResponse $response): void
    {
        $response->assertOk();
        $response->assertViewHas('codes');
    }

    /**
     * Get the recovery codes the recovery-code step shows, asserting it shows that step.
     *
     * @return list<string>
     */
    public function shownRecoveryCodes(TestResponse $response): array
    {
        $this->assertRecoveryCodesPage($response);

        $codes = $response->viewData('codes');

        Assert::assertIsList($codes, 'The recovery-code step shows no list of codes.');

        return $codes;
    }

    /**
     * Assert the response refused the answer with the message on the field, back at enrollment and flashing back no input.
     */
    public function assertEnrollmentRefused(TestResponse $response, string $field, string $message): void
    {
        $response->assertRedirectToRoute('login.enrollment');
        $response->assertSessionHasErrors([$field => $message]);

        Assert::assertSame([], $this->app['session.store']->getOldInput(), 'The response flashed back the answer.');
    }

    /**
     * Assert the response refused an answer to a ceremony that ended with its message on the field, back at enrollment and flashing back no input.
     */
    public function assertEnrollmentExpired(TestResponse $response, string $field): void
    {
        $this->assertEnrollmentRefused($response, $field, __('keystone::messages.enrollment-expired'));
    }

    /**
     * Assert the response refused the answer as invalid on the given fields, flashing back no input.
     *
     * @param  array<int|string, string>  $fields  Field names, or a field's expected message by its name.
     */
    public function assertEnrollmentInvalid(TestResponse $response, array $fields): void
    {
        $response->assertInvalid($fields);

        Assert::assertSame([], $this->app['session.store']->getOldInput(), 'The response flashed back the answer.');
    }

    /**
     * Assert the response sent the user who enrolled their second factor on to save their recovery codes.
     */
    public function assertSecondFactorEnrolled(TestResponse $response): void
    {
        $response->assertRedirectToRoute('login.enrollment');
    }

    /**
     * Assert the response sent the user to sign in again after cancelling the pending sign-in.
     */
    public function assertEnrollmentCancelled(TestResponse $response): void
    {
        $response->assertRedirectToRoute('login');
    }
}
