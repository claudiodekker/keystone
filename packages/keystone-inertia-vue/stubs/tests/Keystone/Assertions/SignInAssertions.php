<?php

namespace Tests\Keystone\Assertions;

use ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions\SignInAssertions as InertiaVueSignInAssertions;

/**
 * Check how this app answers each outcome of signing in. Redefine a method here when the app changes that answer.
 */
trait SignInAssertions
{
    use InertiaVueSignInAssertions;
}
