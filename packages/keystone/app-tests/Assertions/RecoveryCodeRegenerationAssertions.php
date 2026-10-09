<?php

namespace ClaudioDekker\Keystone\AppTests\Assertions;

use ClaudioDekker\Keystone\Methods\RecoveryCodeType;
use ClaudioDekker\Keystone\Status;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
trait RecoveryCodeRegenerationAssertions
{
    /**
     * Assert the response is the page showing the staged replacement recovery codes in the security settings.
     *
     * @param  TestResponse<Response>  $response
     * @param  list<string>  $codes
     */
    public function assertRecoveryCodeRegenerationPage(TestResponse $response, array $codes): void
    {
        $response->assertOk()->assertSeeInOrder($codes);
    }

    /**
     * Assert the response refuses a code typed back that isn't one of the staged set, without flashing it.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertRecoveryCodeRegenerationRefused(TestResponse $response): void
    {
        $response->assertRedirectToRoute('security.recovery-codes.regenerate')
            ->assertSessionHasErrors([RecoveryCodeType::FIELD => __('keystone::messages.recovery_code_mismatch')])
            ->assertSessionMissing('_old_input');
    }

    /**
     * Assert the response sends a code typed after its staged set ended back to stage a fresh set, saying why.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertRecoveryCodeRegenerationExpired(TestResponse $response): void
    {
        $response->assertRedirectToRoute('security.recovery-codes.regenerate')
            ->assertSessionHas(Status::SESSION_KEY, Status::RECOVERY_CODES_EXPIRED->value);
    }

    /**
     * Assert the response sends the user whose staged set was stored to the security page, saying so.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertRecoveryCodesRegenerated(TestResponse $response): void
    {
        $response->assertRedirectToRoute('security')
            ->assertSessionHas(Status::SESSION_KEY, Status::RECOVERY_CODES_REGENERATED->value);
    }

    /**
     * Assert the response sends the user who discarded the staged set to the security page.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertRecoveryCodeRegenerationCancelled(TestResponse $response): void
    {
        $response->assertRedirectToRoute('security');
    }
}
