<?php

namespace Tests\Keystone\Assertions;

use ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions\ChallengeAssertions as InertiaVueChallengeAssertions;

/**
 * Check how this app answers each outcome of the second-factor challenge. Redefine a method here when the app changes that answer.
 */
trait ChallengeAssertions
{
    use InertiaVueChallengeAssertions;
}
