<?php

namespace ClaudioDekker\Keystone\AppTests\Assertions;

use ClaudioDekker\Keystone\Methods\RecoveryCodeType;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
trait RecoveryCodesAssertions
{
    /**
     * Assert the response is the page showing the staged recovery codes.
     *
     * @param  TestResponse<Response>  $response
     * @param  list<string>  $codes
     */
    public function assertRecoveryCodesPage(TestResponse $response, array $codes): void
    {
        $response->assertOk()->assertSeeInOrder($codes);
    }

    /**
     * Assert the response refuses a code typed back that isn't one of the staged set, without flashing it.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertRecoveryCodeRefused(TestResponse $response): void
    {
        $response->assertRedirectToRoute('login.recovery-codes')
            ->assertSessionHasErrors([RecoveryCodeType::FIELD => __('keystone::messages.recovery_code_mismatch')])
            ->assertSessionMissing('_old_input');
    }

    /**
     * Assert the response sends a user whose account owes a second factor before its recovery codes on to enroll it.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertSecondFactorOwed(TestResponse $response): void
    {
        $response->assertRedirectToRoute('login.enrollment');
    }

    /**
     * Assert the response sends the signed-in user on to the intended URL after saving recovery codes.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertRecoveryCodesSaved(TestResponse $response, string $intendedUrl): void
    {
        $response->assertRedirect($intendedUrl);
    }
}
