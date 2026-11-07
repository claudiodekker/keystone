<?php

namespace ClaudioDekker\Keystone\Auth;

use ClaudioDekker\Keystone\Accounts\CredentialEpoch;

/**
 * @api
 */
trait HasKeystone
{
    /**
     * Keep Keystone's columns out of the model's array form.
     */
    public function initializeHasKeystone(): void
    {
        $this->mergeHidden($this->keystoneColumns());
    }

    /**
     * Determine if the attribute may be mass assigned, which none of Keystone's columns may, even while mass assignment is unguarded.
     *
     * @param  string  $key
     */
    public function isFillable($key): bool
    {
        if (in_array($key, $this->keystoneColumns(), true)) {
            return false;
        }

        return parent::isFillable($key);
    }

    /**
     * Get the users columns Keystone keeps, which only Keystone writes.
     *
     * @return list<string>
     */
    protected function keystoneColumns(): array
    {
        return [CredentialEpoch::COLUMN];
    }
}
