<?php

namespace ClaudioDekker\Keystone;

use Illuminate\Support\Facades\Auth;
use LogicException;

/**
 * @internal
 */
class Keystone
{
    /**
     * Get the app's default guard, which must use the keystone driver.
     */
    public static function guard(): KeystoneGuard
    {
        $guard = Auth::guard();

        if (! $guard instanceof KeystoneGuard) {
            throw new LogicException('Keystone needs the default guard to use the keystone driver.');
        }

        return $guard;
    }
}
