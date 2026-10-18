<?php

namespace Tests\Keystone\Assertions;

use ClaudioDekker\Keystone\AppTests\Assertions\SignOutAssertions as KeystoneSignOutAssertions;

/**
 * Check how this app answers signing out. Redefine a method here when the app changes that answer.
 */
trait SignOutAssertions
{
    use KeystoneSignOutAssertions;
}
