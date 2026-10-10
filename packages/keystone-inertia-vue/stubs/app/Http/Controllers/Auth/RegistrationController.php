<?php

namespace App\Http\Controllers\Auth;

use ClaudioDekker\Keystone\Http\Controllers\RegistrationController as Controller;
use ClaudioDekker\Keystone\Http\PageValues\RegisterPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RegistrationController extends Controller
{
    /**
     * Respond with the register page, filled with the address invalid input flashed back and kept encrypted in the browser's history.
     */
    protected function sendRegistrationPage(Request $request, RegisterPage $page): Response
    {
        Inertia::encryptHistory();

        return Inertia::render('auth/Register', [
            'status' => $page->status,
            'email' => $request->old(self::EMAIL),
        ]);
    }

    /**
     * Respond to a handled address, sending the user on to the step telling them to check their inbox.
     */
    protected function sendRegistrationLinkSent(Request $request): RedirectResponse
    {
        return to_route('register.link-sent');
    }

    /**
     * Respond with the step telling the user to check their inbox.
     */
    protected function sendRegistrationLinkSentPage(Request $request): Response
    {
        Inertia::encryptHistory();

        return Inertia::render('auth/RegisterLinkSent');
    }
}
