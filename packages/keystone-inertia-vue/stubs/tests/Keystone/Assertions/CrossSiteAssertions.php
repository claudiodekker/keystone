<?php

namespace Tests\Keystone\Assertions;

use ClaudioDekker\Keystone\AppTests\Assertions\CrossSiteAssertions as KeystoneCrossSiteAssertions;

/**
 * Check how this app answers a cross-site request. Redefine the method here when the app changes that answer.
 */
trait CrossSiteAssertions
{
    use KeystoneCrossSiteAssertions;
}
