<?php

namespace ClaudioDekker\Keystone\AppTests\Assertions;

use ClaudioDekker\Keystone\Status;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
trait EnrollmentAssertions
{
    /**
     * Assert the response is the enrollment page, offering the types in order.
     *
     * @param  TestResponse<Response>  $response
     * @param  list<string>  $types
     */
    public function assertEnrollmentPage(TestResponse $response, array $types): void
    {
        $response->assertOk()->assertSeeInOrder($types);
    }

    /**
     * Assert the response is the type's enrollment form.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertEnrollmentForm(TestResponse $response, string $type): void
    {
        $response->assertOk()->assertSee($type);
    }

    /**
     * Assert the response is the type's enrollment form, saying the earlier ceremony expired.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertEnrollmentRestarted(TestResponse $response, string $type): void
    {
        $response->assertOk()->assertSee($type)->assertSee(Status::ENROLLMENT_EXPIRED->label());
    }

    /**
     * Assert the response refuses the enrollment answer, with the message on the credential type.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertEnrollmentRefused(TestResponse $response, string $type): void
    {
        $response->assertRedirectToRoute('login.enrollment.start', ['type' => $type])
            ->assertSessionHasErrors([$type => __('keystone::messages.invalid_credential')]);
    }

    /**
     * Assert the response refuses invalid enrollment input, with an error for each field.
     *
     * @param  TestResponse<Response>  $response
     * @param  list<string>  $fields
     */
    public function assertEnrollmentInvalid(TestResponse $response, string $type, array $fields): void
    {
        $response->assertRedirectToRoute('login.enrollment.start', ['type' => $type])->assertSessionHasErrors($fields);
    }

    /**
     * Assert the response sends an answer whose ceremony expired back to start the type's enrollment afresh, saying why.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertEnrollmentExpired(TestResponse $response, string $type): void
    {
        $response->assertRedirectToRoute('login.enrollment.start', ['type' => $type])
            ->assertSessionHas(Status::SESSION_KEY, Status::ENROLLMENT_EXPIRED->value);
    }

    /**
     * Assert the response sends a user whose account still owes recovery codes on to save them.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertRecoveryCodesOwed(TestResponse $response): void
    {
        $response->assertRedirectToRoute('login.recovery-codes');
    }

    /**
     * Assert the response sends the signed-in user on to the intended URL after enrolling.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertEnrollmentCompleted(TestResponse $response, string $intendedUrl): void
    {
        $response->assertRedirect($intendedUrl);
    }

    /**
     * Assert the response confirms the cancelled enrollment on the sign-in page.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertEnrollmentCancelled(TestResponse $response): void
    {
        $response->assertRedirectToRoute('login')
            ->assertSessionHas(Status::SESSION_KEY, Status::ENROLLMENT_CANCELLED->value);
    }

    /**
     * Assert the response sends the user back to the offer when the type's ceremony couldn't start, with the message on the type.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertEnrollmentNotStarted(TestResponse $response, string $type): void
    {
        $response->assertRedirectToRoute('login.enrollment')
            ->assertSessionHasErrors([$type => __('keystone::messages.enrollment_failed')]);
    }

    /**
     * Assert the response sends a session holding no sign-in at enrollment to the sign-in page.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertSentToSignInFromEnrollment(TestResponse $response): void
    {
        $response->assertRedirectToRoute('login');
    }

    /**
     * Assert the response tells a browser whose session ended because its account newly owes enrollment to sign in again.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertDemotedToSignIn(TestResponse $response): void
    {
        $response->assertRedirectToRoute('login');
    }

    /**
     * Assert the response refuses a JSON request whose session ended because its account newly owes enrollment.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertDemotedJsonRefused(TestResponse $response): void
    {
        $response->assertUnauthorized()->assertJson(['message' => Status::ENROLLMENT_OWED->label(), 'reason' => 'demoted']);
    }
}
