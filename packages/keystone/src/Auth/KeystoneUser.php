<?php

namespace ClaudioDekker\Keystone\Auth;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * An account Keystone signs in: the app's user model, read through its relations and accessors only.
 *
 * @api
 */
interface KeystoneUser extends Authenticatable
{
    //
}
