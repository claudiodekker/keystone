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
    //
}
