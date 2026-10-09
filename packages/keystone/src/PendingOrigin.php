<?php

namespace ClaudioDekker\Keystone;

/**
 * @internal
 */
enum PendingOrigin: string
{
    case LOGIN = 'login';
    case REGISTRATION = 'registration';

    /**
     * Get the status a cancelled pending sign-in of this origin says goodbye with.
     */
    public function cancelledStatus(): Status
    {
        return match ($this) {
            self::LOGIN => Status::ENROLLMENT_CANCELLED,
            self::REGISTRATION => Status::REGISTRATION_ENROLLMENT_CANCELLED,
        };
    }

    /**
     * Determine if the sign-in that ends a pending sign-in of this origin alerts the owner about a new device, which the browser that just created the account never is.
     */
    public function alertsNewDevice(): bool
    {
        return $this === self::LOGIN;
    }

    /**
     * Determine if finishing the enrollment a pending sign-in of this origin owes records enrollment.completed.
     */
    public function recordsCompletedEnrollment(): bool
    {
        return $this === self::REGISTRATION;
    }
}
