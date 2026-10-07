<?php

namespace ClaudioDekker\Keystone\AppTests\Assertions;

use ClaudioDekker\Keystone\Methods\CredentialTypes;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
trait SudoAssertions
{
    /**
     * Assert the response is the sudo page, offering the types in order.
     *
     * @param  TestResponse<Response>  $response
     * @param  list<string>  $types
     */
    public function assertSudoPage(TestResponse $response, array $types): void
    {
        $response->assertOk()->assertSeeInOrder($types);
    }

    /**
     * Assert the response refuses the answer, with the message on the credential type.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertSudoRefused(TestResponse $response, string $type): void
    {
        $response->assertRedirectToRoute('sudo')
            ->assertSessionHasErrors([$type => __('keystone::messages.invalid_credential')]);
    }

    /**
     * Assert the response refuses the account's last recovery code, saying it is kept for account recovery.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertLastRecoveryCodeKeptAtSudo(TestResponse $response): void
    {
        $response->assertRedirectToRoute('sudo')
            ->assertSessionHasErrors([CredentialTypes::RECOVERY_CODE => __('keystone::messages.last_recovery_code')]);
    }

    /**
     * Assert the response sends a user who passed the first step, but whose account still owes the challenge, on to it.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertSudoChallengeOwed(TestResponse $response): void
    {
        $response->assertRedirectToRoute('sudo');
    }

    /**
     * Assert the response sends the user whose sudo was granted on to the intended URL.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertSudoGranted(TestResponse $response, string $intendedUrl): void
    {
        $response->assertRedirect($intendedUrl);
    }

    /**
     * Assert the response sends the user whose sudo ended back to the page they came from, which is the root when they came from none.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertSudoEnded(TestResponse $response): void
    {
        $response->assertRedirect('/');
    }

    /**
     * Assert the response sends a browser the sudo gate refused to the sudo page.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertSudoRequired(TestResponse $response): void
    {
        $response->assertRedirectToRoute('sudo');
    }

    /**
     * Assert the response refuses a JSON request the sudo gate refused with a 403 that names sudo as the reason.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertSudoForbidden(TestResponse $response): void
    {
        $response->assertForbidden()->assertJson(['reason' => 'sudo']);
    }

    /**
     * Assert the response sends a session nothing was demanded of away from the sudo steps.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertSentAwayWithoutSudoInProgress(TestResponse $response): void
    {
        $response->assertRedirect('/');
    }

    /**
     * Assert the response sends a guest away from the signed-in-only sudo steps.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertGuestSentAway(TestResponse $response): void
    {
        $response->assertRedirectToRoute('login');
    }

    /**
     * Assert the response refuses the sudo step because its rate limit is spent, saying when to try again.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertSudoThrottled(TestResponse $response): void
    {
        $seconds = $response->headers->get('Retry-After');

        $response->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertSee(__('keystone::messages.throttled', ['seconds' => $seconds]));
    }
}
