<?php

namespace ClaudioDekker\Keystone\AppTests\Assertions;

use ClaudioDekker\Keystone\Status;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
trait CredentialEnrollmentAssertions
{
    /**
     * Assert the response is the type's enrollment form in the security settings.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertCredentialEnrollmentForm(TestResponse $response, string $type): void
    {
        $response->assertOk()->assertSee($type);
    }

    /**
     * Assert the response is the type's enrollment form in the security settings, saying the earlier ceremony expired.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertCredentialEnrollmentRestarted(TestResponse $response, string $type): void
    {
        $response->assertOk()->assertSee($type)->assertSee(Status::ENROLLMENT_EXPIRED->label());
    }

    /**
     * Assert the response sends the user back to the security page from a type whose enrollment couldn't start, with the message on the type.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertCredentialEnrollmentNotStarted(TestResponse $response, string $type): void
    {
        $response->assertRedirectToRoute('security')
            ->assertSessionHasErrors([$type => __('keystone::messages.enrollment_failed')]);
    }

    /**
     * Assert the response refuses the enrollment answer, with the message on the credential type.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertCredentialEnrollmentRefused(TestResponse $response, string $type): void
    {
        $response->assertRedirectToRoute('security.enroll', ['type' => $type])
            ->assertSessionHasErrors([$type => __('keystone::messages.invalid_credential')]);
    }

    /**
     * Assert the response sends an answer whose ceremony expired back to start the type's enrollment afresh, saying why.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertCredentialEnrollmentExpired(TestResponse $response, string $type): void
    {
        $response->assertRedirectToRoute('security.enroll', ['type' => $type])
            ->assertSessionHas(Status::SESSION_KEY, Status::ENROLLMENT_EXPIRED->value);
    }

    /**
     * Assert the response sends the user whose credential was stored to the security page, saying so.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertCredentialEnrolled(TestResponse $response): void
    {
        $response->assertRedirectToRoute('security')
            ->assertSessionHas(Status::SESSION_KEY, Status::ENROLLED->value);
    }

    /**
     * Assert the response sends the user who cancelled an enrollment to the security page.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertCredentialEnrollmentCancelled(TestResponse $response): void
    {
        $response->assertRedirectToRoute('security');
    }
}
