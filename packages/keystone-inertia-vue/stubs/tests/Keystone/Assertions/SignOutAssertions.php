<?php

namespace Tests\Keystone\Assertions;

use ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions\SignOutAssertions as InertiaVueSignOutAssertions;

/**
 * Check how this app answers signing out. Redefine a method here when the app changes that answer.
 */
trait SignOutAssertions
{
    use InertiaVueSignOutAssertions;
}
