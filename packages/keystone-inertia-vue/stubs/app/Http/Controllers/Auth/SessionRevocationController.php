<?php

namespace App\Http\Controllers\Auth;

use ClaudioDekker\Keystone\Http\Controllers\SessionRevocationController as Controller;
use ClaudioDekker\Keystone\Http\PageValues\SessionRow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SessionRevocationController extends Controller
{
    /**
     * Respond with the page confirming the session's sign-out, kept encrypted in the browser's history.
     */
    protected function sendRevocationPage(Request $request, SessionRow $session): Response
    {
        Inertia::encryptHistory();

        return Inertia::render('settings/SessionRevocation', [
            'handle' => $session->handle,
            'platform' => $session->platform,
            'browser' => $session->browser,
            'ipAddress' => $session->ipAddress,
            'location' => $session->location,
            'lastActiveAt' => $session->lastActiveAt,
            'current' => $session->current,
        ]);
    }

    /**
     * Respond to a refused sign-out, sending the user to the security page with the message.
     */
    protected function sendRevocationRefused(Request $request, string $session, string $message): RedirectResponse
    {
        return to_route('security')->withErrors(['session' => $message]);
    }

    /**
     * Respond to a signed-out session, sending the user to the security page.
     */
    protected function sendSessionRevoked(Request $request): RedirectResponse
    {
        return to_route('security');
    }

    /**
     * Respond to a session the account doesn't have, sending the user to the security page.
     */
    protected function sendSessionNotFound(Request $request): RedirectResponse
    {
        return to_route('security');
    }

    /**
     * Respond to a session driver that can't list sessions, sending the user to the security page.
     */
    protected function sendSessionsUnavailable(Request $request): RedirectResponse
    {
        return to_route('security');
    }
}
