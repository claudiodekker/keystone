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
     * Determine if the sign-in that ends a pending sign-in of this origin alerts the owner about a new device, which the browser that just created the account never is.
     */
    public function alertsNewDevice(): bool
    {
        return $this === self::LOGIN;
    }
}
