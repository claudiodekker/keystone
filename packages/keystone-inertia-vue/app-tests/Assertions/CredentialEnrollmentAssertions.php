<?php

namespace ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions;

use ClaudioDekker\Keystone\AppTests\Assertions\CredentialEnrollmentAssertions as KeystoneCredentialEnrollmentAssertions;
use ClaudioDekker\Keystone\Status;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
trait CredentialEnrollmentAssertions
{
    use KeystoneCredentialEnrollmentAssertions;

    /**
     * Assert the response is the type's enrollment form in the security settings.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertCredentialEnrollmentForm(TestResponse $response, string $type): void
    {
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('settings/CredentialEnrollment')->where('type', $type));
    }

    /**
     * Assert the response is the type's enrollment form in the security settings, saying the earlier ceremony expired.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertCredentialEnrollmentRestarted(TestResponse $response, string $type): void
    {
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/CredentialEnrollment')
            ->where('type', $type)
            ->where('status', Status::ENROLLMENT_EXPIRED->label()));
    }
}
