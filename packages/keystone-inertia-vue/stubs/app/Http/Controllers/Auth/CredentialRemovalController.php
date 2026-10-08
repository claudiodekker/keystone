<?php

namespace App\Http\Controllers\Auth;

use ClaudioDekker\Keystone\Http\Controllers\CredentialRemovalController as Controller;
use ClaudioDekker\Keystone\Http\PageValues\CredentialRemovalPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CredentialRemovalController extends Controller
{
    /**
     * Respond with the page confirming the credential's removal, kept encrypted in the browser's history.
     */
    protected function sendRemovalPage(Request $request, CredentialRemovalPage $page): Response
    {
        Inertia::encryptHistory();

        return Inertia::render('settings/CredentialRemoval', [
            'id' => $page->id,
            'type' => $page->type,
            'label' => $page->label,
            'listed' => $page->listed,
        ]);
    }

    /**
     * Respond to a refused removal, sending the user back to the confirm step with the message.
     */
    protected function sendRemovalRefused(Request $request, int $credential, string $message): RedirectResponse
    {
        return to_route('security.credentials.remove', ['credential' => $credential])->withErrors(['credential' => $message]);
    }

    /**
     * Respond to a removed credential, sending the user to the security page.
     */
    protected function sendCredentialRemoved(Request $request): RedirectResponse
    {
        return to_route('security');
    }

    /**
     * Respond to a credential the account doesn't hold, sending the user to the security page.
     */
    protected function sendCredentialNotFound(Request $request): RedirectResponse
    {
        return to_route('security');
    }
}
