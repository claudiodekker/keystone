<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Methods\Surface;
use LogicException;

/**
 * @internal
 */
enum Flow: string
{
    case SIGN_IN = 'sign-in';

    /**
     * Derive the flow from the session's phase and the surface in use.
     */
    public static function of(KeystoneGuard $guard, Surface $surface): self
    {
        if ($surface === Surface::SIGN_IN && $guard->guest()) {
            return self::SIGN_IN;
        }

        throw new LogicException("No flow uses the [{$surface->value}] surface in this session's phase.");
    }
}
