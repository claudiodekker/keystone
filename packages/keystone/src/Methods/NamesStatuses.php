<?php

namespace ClaudioDekker\Keystone\Methods;

use ClaudioDekker\Keystone\Status;

/**
 * @internal
 */
interface NamesStatuses
{
    /**
     * Get the status shown once a credential of the type took the place of the ones the account held.
     */
    public function replacedStatus(): Status;

    /**
     * Get the status shown once a credential of the type was removed.
     */
    public function removedStatus(): Status;
}
