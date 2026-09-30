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
    case CHALLENGE = 'challenge';

    /**
     * Derive the flow from the session's phase and the surface in use.
     */
    public static function of(KeystoneGuard $guard, Surface $surface): self
    {
        return match (true) {
            $surface === Surface::SIGN_IN && $guard->guest() => self::SIGN_IN,
            $surface === Surface::CHALLENGE && $guard->guest() && $guard->isPendingAt(PendingStage::CHALLENGE) => self::CHALLENGE,
            default => throw new LogicException("No flow uses the [{$surface->value}] surface in this session's phase."),
        };
    }

    /**
     * Determine if the flow sits behind a first factor, where a guessable type's failures share one count.
     */
    public function sharesFailedAttempts(): bool
    {
        return $this === self::CHALLENGE;
    }
}
