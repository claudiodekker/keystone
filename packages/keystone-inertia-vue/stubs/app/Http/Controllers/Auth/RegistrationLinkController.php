<?php

namespace App\Http\Controllers\Auth;

use ClaudioDekker\Keystone\Http\Controllers\RegistrationLinkController as Controller;
use ClaudioDekker\Keystone\Http\PageValues\EmailedLinkPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RegistrationLinkController extends Controller
{
    /**
     * Respond with the mailed link's one-button step, kept encrypted in the browser's history since its action carries the link.
     */
    protected function sendRegistrationLinkPage(Request $request, EmailedLinkPage $page): Response
    {
        Inertia::encryptHistory();

        return Inertia::render('auth/EmailedLink', [
            'action' => $page->action,
        ]);
    }

    /**
     * Respond to a spent link, sending the user on to finish registering.
     */
    protected function sendRegistrationLinkConsumed(Request $request): RedirectResponse
    {
        return to_route('register.finish');
    }

    /**
     * Respond to a link that no longer works, sending the user on to "link expired".
     */
    protected function sendRegistrationLinkExpired(Request $request): RedirectResponse
    {
        return to_route('register.link-expired');
    }

    /**
     * Respond with the step saying the link no longer works.
     */
    protected function sendRegistrationLinkExpiredPage(Request $request): Response
    {
        Inertia::encryptHistory();

        return Inertia::render('auth/RegisterLinkExpired');
    }
}
