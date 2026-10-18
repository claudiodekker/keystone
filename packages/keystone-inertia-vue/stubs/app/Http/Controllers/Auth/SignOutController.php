<?php

namespace App\Http\Controllers\Auth;

use ClaudioDekker\Keystone\Http\Controllers\SignOutController as KeystoneSignOutController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Answer signing out. Keystone ends the session; this decides where users land.
 */
class SignOutController extends KeystoneSignOutController
{
    /**
     * Clear the browser history and return to the sign-in page.
     */
    protected function sendSignedOut(Request $request): RedirectResponse
    {
        Inertia::clearHistory();

        return to_route('login');
    }
}
