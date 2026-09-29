<?php

namespace App\Http\Controllers\Keystone;

use ClaudioDekker\Keystone\Http\Controllers\SignOutController as Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SignOutController extends Controller
{
    /**
     * Respond to a completed sign-out, clearing the browser's history of the session's pages.
     */
    protected function sendSignedOut(Request $request): RedirectResponse
    {
        Inertia::clearHistory();

        return to_route('login');
    }
}
