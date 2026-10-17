<?php

namespace ClaudioDekker\Keystone\Auth;

use Illuminate\Auth\SessionGuard;

/**
 * Resolve the signed-in account from the session, and sign nobody in through any SessionGuard method.
 *
 * @internal
 */
class KeystoneGuard extends SessionGuard
{
    /**
     * Sign the user out and end the whole session, so nothing it held outlives them, Auth::logout() included.
     */
    public function logout(): void
    {
        parent::logout();

        $this->session->invalidate();
        $this->session->regenerateToken();
    }
}
