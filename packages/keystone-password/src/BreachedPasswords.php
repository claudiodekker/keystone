<?php

namespace ClaudioDekker\Keystone\Password;

/**
 * @api
 */
interface BreachedPasswords
{
    /**
     * Determine if the password has appeared in a known breach.
     *
     * A source that can't answer says no and logs why, so an outage never stops a user from setting a password.
     */
    public function isBreached(#[\SensitiveParameter] string $password): bool;
}
