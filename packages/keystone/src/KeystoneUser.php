<?php

namespace ClaudioDekker\Keystone;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * @api
 */
interface KeystoneUser extends Authenticatable
{
    /**
     * Get the name of the "deleted at" column.
     *
     * @return string
     */
    public function getDeletedAtColumn();
}
