<?php

namespace Tests\Keystone;

use ClaudioDekker\Keystone\AppTests\TestCase as KeystoneTestCase;
use Tests\Keystone\Assertions\ChallengeAssertions;
use Tests\Keystone\Assertions\CrossSiteAssertions;
use Tests\Keystone\Assertions\EnrollmentAssertions;
use Tests\Keystone\Assertions\GuestStepAssertions;
use Tests\Keystone\Assertions\SessionExpiryAssertions;
use Tests\Keystone\Assertions\SignInAssertions;
use Tests\Keystone\Assertions\SignOutAssertions;
use Tests\Keystone\Assertions\SudoAssertions;
use Tests\Keystone\Assertions\ThrottledAssertions;

/**
 * Run Keystone's AppTests against this app, checking its answers through the app's own assertions.
 */
abstract class TestCase extends KeystoneTestCase
{
    use ChallengeAssertions;
    use CrossSiteAssertions;
    use EnrollmentAssertions;
    use GuestStepAssertions;
    use SessionExpiryAssertions;
    use SignInAssertions;
    use SignOutAssertions;
    use SudoAssertions;
    use ThrottledAssertions;
}
