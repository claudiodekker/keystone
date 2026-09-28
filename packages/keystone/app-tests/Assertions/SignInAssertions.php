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
     * Assert the response is the sign-in page.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertSignInPage(TestResponse $response): void
    {
        $response->assertOk();
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
