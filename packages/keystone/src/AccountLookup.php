<?php

namespace ClaudioDekker\Keystone;

/**
 * @api
 */
class AccountLookup
{
    /**
     * Get the id of the account the typed identifier names.
     */
    public function handle(string $identifier): int|string|null
    {
        return (new Addresses(Keystone::guard()->userModel()))->resolve($identifier);
    }
}
