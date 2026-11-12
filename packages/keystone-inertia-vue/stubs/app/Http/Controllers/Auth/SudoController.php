<?php

namespace App\Http\Controllers\Auth;

use ClaudioDekker\Keystone\Http\Controllers\SudoController as KeystoneSudoController;
use ClaudioDekker\Keystone\Http\Pages\SudoPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Answer every outcome of confirming it is the user before a change that needs sudo. Keystone runs the flow; this decides what users see.
 */
class SudoController extends KeystoneSudoController
{
    /**
     * Render the sudo page, with the browser history encrypted, and the page value's fields as props.
     */
    protected function sendSudoPage(Request $request, SudoPage $page): Response
    {
        Inertia::encryptHistory();

        return Inertia::render('auth/Sudo', [
            'preselected' => $page->preselected,
            'types' => $page->types,
        ]);
    }

    /**
     * Send a session without a sudo-in-progress on to where it was going.
     */
    protected function sendNoSudoInProgress(Request $request, string $redirectTo): RedirectResponse
    {
        return redirect($redirectTo);
    }

    /**
     * Return to the sudo page with the message on the type's field, flashing back no input.
     */
    protected function sendSudoRefused(Request $request, string $field, string $message): RedirectResponse
    {
        return to_route('sudo')->withErrors([$field => $message]);
    }

    /**
     * Send the user on to the sudo page's second step.
     */
    protected function sendSudoSecondStep(Request $request): RedirectResponse
    {
        return to_route('sudo');
    }

    /**
     * Send the user on to where they were going once sudo is granted.
     */
    protected function sendSudoGranted(Request $request, string $redirectTo): RedirectResponse
    {
        return redirect($redirectTo);
    }
}
