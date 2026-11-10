<?php

namespace Tests\Keystone\Assertions;

use ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions\EnrollmentAssertions as InertiaVueEnrollmentAssertions;

/**
 * Check how this app answers each outcome of enrolling what a sign-in owes. Redefine a method here when the app changes that answer.
 */
trait EnrollmentAssertions
{
    use InertiaVueEnrollmentAssertions;
}
