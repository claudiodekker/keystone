<?php

namespace ClaudioDekker\Keystone\AppTests\Assertions;

use ClaudioDekker\Keystone\Http\Controllers\SignInController;
use ClaudioDekker\Keystone\Status;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
trait RegistrationFinishAssertions
{
    /**
     * Assert the response is the page that finishes registering the address.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertRegistrationFinishPage(TestResponse $response, string $address): void
    {
        $response->assertOk()->assertSee($address);
    }

    /**
     * Assert the response sends a session that holds no registering address back to register.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertSentBackToRegister(TestResponse $response): void
    {
        $response->assertRedirectToRoute('register');
    }

    /**
     * Assert the response sends a finish whose session holds no registering address back to register, saying the registration expired.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertRegistrationExpired(TestResponse $response): void
    {
        $response->assertRedirectToRoute('register')
            ->assertSessionHas(Status::SESSION_KEY, Status::REGISTRATION_EXPIRED->value);
    }

    /**
     * Assert the response refuses an account created barred from signing in as a refused sign-in, on the sign-in page.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertRegistrationBarred(TestResponse $response): void
    {
        $response->assertRedirectToRoute('login')
            ->assertSessionHasErrors([SignInController::IDENTIFIER => __('keystone::messages.failed')]);
    }

    /**
     * Assert the response sends the new, signed-in account on to the intended URL.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertRegistered(TestResponse $response, string $intendedUrl): void
    {
        $response->assertRedirect($intendedUrl);
    }

    /**
     * Assert the response sends the new account on to the enrollment it owes.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertRegistrationEnrollmentOwed(TestResponse $response): void
    {
        $response->assertRedirectToRoute('login.enrollment');
    }

    /**
     * Assert the response refuses the credential, with the message on the credential type.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertRegistrationRefused(TestResponse $response, string $type): void
    {
        $response->assertRedirectToRoute('register.finish')
            ->assertSessionHasErrors([$type => __('keystone::messages.invalid_credential')]);
    }

    /**
     * Assert the response refuses invalid input to the finish, with an error for each field.
     *
     * @param  TestResponse<Response>  $response
     * @param  list<string>  $fields
     */
    public function assertRegistrationFinishInvalid(TestResponse $response, array $fields): void
    {
        $response->assertRedirectToRoute('register.finish')->assertSessionHasErrors($fields);
    }

    /**
     * Assert the response confirms the cancelled registration on the register page.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertRegistrationCancelled(TestResponse $response): void
    {
        $response->assertRedirectToRoute('register')
            ->assertSessionHas(Status::SESSION_KEY, Status::REGISTRATION_CANCELLED->value);
    }

    /**
     * Assert the response sends the user to sign in, saying the address is already registered.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertAddressTaken(TestResponse $response): void
    {
        $response->assertRedirectToRoute('login')
            ->assertSessionHas(Status::SESSION_KEY, Status::ADDRESS_ALREADY_REGISTERED->value);
    }
}
