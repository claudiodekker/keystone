<?php

namespace ClaudioDekker\Keystone\Http\Controllers;

use Illuminate\Routing\Controllers\HasMiddleware;

/**
 * A controller whose routes are Keystone's: they carry the hardening floor and the cross-site check, whatever the app's subclass overrides.
 *
 * @api
 */
interface KeystoneController extends HasMiddleware
{
    //
}
