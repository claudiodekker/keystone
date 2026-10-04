<?php

namespace ClaudioDekker\Keystone\AppTests\Assertions;

use ClaudioDekker\Keystone\Http\Controllers\SignInController;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
trait SignInAssertions
{
    /**
     * Assert the response is the sign-in page, showing the status message when one is given.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertSignInPage(TestResponse $response, ?string $status = null): void
    {
        $response->assertOk();

        if ($status !== null) {
            $response->assertSee($status);
        }
    }

    /**
     * Assert the response refuses the sign-in.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertSignInRefused(TestResponse $response): void
    {
        $response->assertRedirectToRoute('login')
            ->assertSessionHasErrors([SignInController::IDENTIFIER => __('keystone::messages.failed')]);
    }

    /**
     * Assert the response refuses a sign-in step because a rate limit is spent, saying when to try again.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertSignInThrottled(TestResponse $response): void
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
    public function assertSignInInvalid(TestResponse $response, array $fields): void
    {
        $response->assertRedirectToRoute('login')->assertSessionHasErrors($fields);
    }

    /**
     * Assert the response sends a user whose sign-in is held for a challenge on to it.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertChallengeOwed(TestResponse $response): void
    {
        $response->assertRedirectToRoute('login.challenge');
    }

    /**
     * Assert the response sends a user whose account owes an enrollment on to enroll.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertEnrollmentOwed(TestResponse $response): void
    {
        $response->assertRedirectToRoute('login.enrollment');
    }

    /**
     * Assert the response sends the signed-in user on to the intended URL.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertSignedIn(TestResponse $response, string $intendedUrl): void
    {
        $response->assertRedirect($intendedUrl);
    }

    /**
     * Assert the response sends a signed-in user away from the guest-only sign-in steps.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertSignedInSentAway(TestResponse $response): void
    {
        $response->assertRedirect('/');
    }
}
