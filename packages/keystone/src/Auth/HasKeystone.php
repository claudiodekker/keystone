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
        $this->mergeHidden([CredentialEpoch::COLUMN]);
    }
}
