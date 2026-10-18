<?php

namespace Tests\Keystone\Assertions;

use ClaudioDekker\Keystone\AppTests\Assertions\ThrottledAssertions as KeystoneThrottledAssertions;

/**
 * Check how this app answers a spent rate limit. Redefine the method here when the app changes that answer.
 */
trait ThrottledAssertions
{
    use KeystoneThrottledAssertions;
}
