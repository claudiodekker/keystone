<?php

namespace App\Http\Controllers\Auth;

use ClaudioDekker\Keystone\Http\Controllers\SudoController as Controller;
use ClaudioDekker\Keystone\Http\PageValues\SudoPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SudoController extends Controller
{
    /**
     * Respond with the sudo page, kept encrypted in the browser's history.
     */
    protected function sendSudoPage(Request $request, SudoPage $page): Response
    {
        Inertia::encryptHistory();

        return Inertia::render('auth/Sudo', [
            'types' => $page->types,
            'preselect' => $page->preselect,
            'surface' => $page->surface,
        ]);
    }

    /**
     * Respond to a refused answer, with the message for the credential type's field.
     */
    protected function sendSudoRefused(Request $request, string $type, string $message): RedirectResponse
    {
        return to_route('sudo')->withErrors([$type => $message]);
    }

    /**
     * Respond to a passed first factor whose account still owes the challenge, sending the user on to it on the same page.
     */
    protected function sendSudoChallengeOwed(Request $request): RedirectResponse
    {
        return to_route('sudo');
    }

    /**
     * Respond to a granted sudo, sending the user on to the page they were refused.
     */
    protected function sendSudoGranted(Request $request, string $intendedUrl): RedirectResponse
    {
        return redirect($intendedUrl);
    }

    /**
     * Respond to an ended sudo, sending the user to the security page.
     */
    protected function sendSudoEnded(Request $request): RedirectResponse
    {
        return to_route('security');
    }
}
