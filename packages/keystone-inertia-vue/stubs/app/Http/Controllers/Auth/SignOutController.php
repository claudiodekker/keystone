<?php

namespace App\Http\Controllers\Auth;

use ClaudioDekker\Keystone\Http\Controllers\SignOutController as KeystoneSignOutController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Answer signing out. Keystone ends the session; this decides where users land.
 */
class SignOutController extends KeystoneSignOutController
{
    /**
     * Return to the sign-in page.
     */
    protected function sendSignedOut(Request $request): RedirectResponse
    {
        return to_route('login');
    }
}
