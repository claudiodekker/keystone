<?php

namespace Tests\Keystone\Assertions;

use ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions\SudoAssertions as InertiaVueSudoAssertions;

/**
 * Check how this app answers each outcome of confirming it is the user before a change that needs sudo. Redefine a method here when the app changes that answer.
 */
trait SudoAssertions
{
    use InertiaVueSudoAssertions;
}
