<?php

namespace ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions;

use ClaudioDekker\Keystone\AppTests\Assertions\SignOutAssertions as KeystoneSignOutAssertions;
use Illuminate\Testing\TestResponse;
use Inertia\Support\SessionKey;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * Check how an Inertia app answers signing out.
 *
 * @api
 */
trait SignOutAssertions
{
    use KeystoneSignOutAssertions {
        assertSignedOut as private assertSignedOutRedirect;
    }

    /**
     * Assert the response sent the signed-out user to sign in again, and that the next page clears the browser history.
     */
    public function assertSignedOut(TestResponse $response): void
    {
        $this->assertSignedOutRedirect($response);

        PHPUnit::assertTrue(session(SessionKey::CLEAR_HISTORY, false), 'The next page does not clear the browser history.');
    }
}
