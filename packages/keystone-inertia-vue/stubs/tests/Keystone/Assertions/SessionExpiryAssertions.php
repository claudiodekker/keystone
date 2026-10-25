<?php

namespace Tests\Keystone\Assertions;

use ClaudioDekker\Keystone\AppTests\Assertions\SessionExpiryAssertions as KeystoneSessionExpiryAssertions;

/**
 * Check how this app answers a request made with a session that expired. Redefine a method here when the app changes that answer.
 */
trait SessionExpiryAssertions
{
    use KeystoneSessionExpiryAssertions;
}
