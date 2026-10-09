<?php

namespace App\Http\Controllers\Auth;

use ClaudioDekker\Keystone\Http\Controllers\RecoveryCodeRegenerationController as Controller;
use ClaudioDekker\Keystone\Http\PageValues\RecoveryCodeRegenerationPage;
use ClaudioDekker\Keystone\Methods\RecoveryCodeType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RecoveryCodeRegenerationController extends Controller
{
    /**
     * Respond with the staged set in the security settings, kept encrypted in the browser's history.
     */
    protected function sendRecoveryCodeRegenerationPage(Request $request, RecoveryCodeRegenerationPage $page): Response
    {
        Inertia::encryptHistory();

        return Inertia::render('settings/RegenerateRecoveryCodes', [
            'codes' => $page->codes,
            'replaces' => $page->replaces,
            'status' => $page->status,
        ]);
    }

    /**
     * Respond to a typed code that isn't one of the staged set, with the message for its field.
     */
    protected function sendRecoveryCodeRegenerationRefused(Request $request, string $message): RedirectResponse
    {
        return to_route('security.recovery-codes.regenerate')->withErrors([RecoveryCodeType::FIELD => $message]);
    }

    /**
     * Respond to a code typed back after its staged set ended, sending the user back for a fresh set.
     */
    protected function sendRecoveryCodeRegenerationExpired(Request $request): RedirectResponse
    {
        return to_route('security.recovery-codes.regenerate');
    }

    /**
     * Respond to the saved set, sending the user to the security page.
     */
    protected function sendRecoveryCodesRegenerated(Request $request): RedirectResponse
    {
        return to_route('security');
    }

    /**
     * Respond to a discarded regeneration, sending the user to the security page.
     */
    protected function sendRecoveryCodeRegenerationCancelled(Request $request): RedirectResponse
    {
        return to_route('security');
    }
}
