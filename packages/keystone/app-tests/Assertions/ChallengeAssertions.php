<?php

namespace ClaudioDekker\Keystone\AppTests\Assertions;

use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Status;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
trait ChallengeAssertions
{
    /**
     * Assert the response is the challenge page, offering the types in order.
     *
     * @param  TestResponse<Response>  $response
     * @param  list<string>  $types
     */
    public function assertChallengePage(TestResponse $response, array $types): void
    {
        $response->assertOk()->assertSeeInOrder($types);
    }

    /**
     * Assert the response sends a user whose second factor no listed type can answer on to recover the account, saying why.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertSecondFactorUnavailable(TestResponse $response): void
    {
        $response->assertRedirectToRoute('login')
            ->assertSessionHas(Status::SESSION_KEY, Status::SECOND_FACTOR_UNAVAILABLE->value);
    }

    /**
     * Assert the response refuses the answer, with the message on the credential type.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertChallengeRefused(TestResponse $response, string $type): void
    {
        $response->assertRedirectToRoute('login.challenge')
            ->assertSessionHasErrors([$type => __('keystone::messages.invalid_credential')]);
    }

    /**
     * Assert the response refuses the account's last recovery code, saying it is kept for account recovery.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertLastRecoveryCodeKept(TestResponse $response): void
    {
        $response->assertRedirectToRoute('login.challenge')
            ->assertSessionHasErrors([CredentialTypes::RECOVERY_CODE => __('keystone::messages.last_recovery_code')]);
    }

    /**
     * Assert the response refuses a step of the challenge because a rate limit is spent, saying when to try again.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertChallengeThrottled(TestResponse $response): void
    {
        $seconds = $response->headers->get('Retry-After');

        $response->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertSee(__('keystone::messages.throttled', ['seconds' => $seconds]));
    }

    /**
     * Assert the response refuses invalid input, with an error for each field.
     *
     * @param  TestResponse<Response>  $response
     * @param  list<string>  $fields
     */
    public function assertChallengeInvalid(TestResponse $response, array $fields): void
    {
        $response->assertRedirectToRoute('login.challenge')->assertSessionHasErrors($fields);
    }

    /**
     * Assert the response sends the signed-in user on to the intended URL.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertChallengePassed(TestResponse $response, string $intendedUrl): void
    {
        $response->assertRedirect($intendedUrl);
    }

    /**
     * Assert the response confirms the cancelled sign-in on the sign-in page.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertChallengeCancelled(TestResponse $response): void
    {
        $response->assertRedirectToRoute('login')
            ->assertSessionHas(Status::SESSION_KEY, Status::SIGN_IN_CANCELLED->value);
    }

    /**
     * Assert the response sends a session holding no sign-in at the challenge to the sign-in page.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertSentToSignIn(TestResponse $response): void
    {
        $response->assertRedirectToRoute('login');
    }

    /**
     * Assert the response sends a signed-in user away from the challenge.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertSignedInSentAwayFromChallenge(TestResponse $response): void
    {
        $response->assertRedirect('/');
    }
}
