<?php

namespace ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions;

use ClaudioDekker\Keystone\AppTests\Assertions\EnrollmentAssertions as KeystoneEnrollmentAssertions;
use ClaudioDekker\Keystone\Status;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
trait EnrollmentAssertions
{
    use KeystoneEnrollmentAssertions;

    /**
     * Assert the response is the enrollment page, offering the types in order.
     *
     * @param  TestResponse<Response>  $response
     * @param  list<string>  $types
     */
    public function assertEnrollmentPage(TestResponse $response, array $types): void
    {
        $response->assertInertia(function (AssertableInertia $page) use ($types) {
            $page->component('auth/Enrollment')
                ->where('types', fn ($offered) => collect($offered)->pluck('type')->all() === $types)
                ->where('preselect', $types[0] ?? null);
        });
    }

    /**
     * Assert the response is the type's enrollment form.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertEnrollmentForm(TestResponse $response, string $type): void
    {
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('auth/EnrollmentForm')->where('type', $type));
    }

    /**
     * Assert the response is the type's enrollment form, saying the earlier ceremony expired.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertEnrollmentRestarted(TestResponse $response, string $type): void
    {
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/EnrollmentForm')
            ->where('type', $type)
            ->where('status', Status::ENROLLMENT_EXPIRED->label()));
    }
}
