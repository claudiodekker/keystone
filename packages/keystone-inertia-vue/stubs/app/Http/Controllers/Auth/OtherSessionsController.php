<?php

namespace App\Http\Controllers\Auth;

use ClaudioDekker\Keystone\Http\Controllers\OtherSessionsController as Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OtherSessionsController extends Controller
{
    /**
     * Respond with the page confirming the sign-out of every other session.
     */
    protected function sendSignOutOthersPage(Request $request): Response
    {
        return Inertia::render('settings/SignOutOthers');
    }

    /**
     * Respond to the account's other sessions being signed out, sending the user to the security page.
     */
    protected function sendOtherSessionsRevoked(Request $request): RedirectResponse
    {
        return to_route('security');
    }
}
