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
    case ENROLLMENT = 'enrollment';
    case SUDO = 'sudo';
    case SETTINGS = 'settings';
    case REGISTRATION = 'registration';

    /**
     * Derive the flow from the session's phase and the surface in use.
     */
    public static function of(KeystoneGuard $guard, Surface $surface): self
    {
        return match (true) {
            $surface === Surface::SIGN_IN && $guard->guest() => self::SIGN_IN,
            $surface === Surface::REGISTRATION && $guard->guest() => self::REGISTRATION,
            $surface === Surface::CHALLENGE && $guard->guest() && $guard->isPendingAt(PendingStage::CHALLENGE) => self::CHALLENGE,
            $guard->check() && $guard->sudoInProgress()?->surface() === $surface => self::SUDO,
            $surface === Surface::ENROLLMENT && $guard->check() => self::SETTINGS,
            default => throw new LogicException("No flow uses the [{$surface->value}] surface in this session's phase."),
        };
    }

    /**
     * Determine if the flow sits behind a first factor, where a guessable type's failures share one count.
     */
    public function sharesFailedAttempts(): bool
    {
        return $this === self::CHALLENGE || $this === self::SUDO;
    }

    /**
     * Get the event a refused answer in the flow records.
     */
    public function rejectionType(): SecurityEventType
    {
        return $this === self::SUDO ? SecurityEventType::SUDO_FAILED : SecurityEventType::PROOF_REJECTED;
    }
}
