<?php

namespace App\Http\Controllers\Auth;

use ClaudioDekker\Keystone\Http\Controllers\SignInController as KeystoneSignInController;
use ClaudioDekker\Keystone\Http\Pages\SignInPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Answer every outcome of signing in. Keystone runs the flow; this decides what users see.
 */
class SignInController extends KeystoneSignInController
{
    /**
     * Render the sign-in page, with the browser history encrypted, and the page value's fields as props.
     */
    protected function sendSignInPage(Request $request, SignInPage $page): Response
    {
        Inertia::encryptHistory();

        return Inertia::render('auth/Login', [
            'status' => $page->status,
            'types' => $page->types,
        ]);
    }

    /**
     * Send a signed-in user home.
     */
    protected function sendAlreadySignedIn(Request $request): RedirectResponse
    {
        return redirect('/');
    }

    /**
     * Send the user on to where the sign-in lands.
     */
    protected function sendSignedIn(Request $request, string $redirectTo): RedirectResponse
    {
        return redirect($redirectTo);
    }

    /**
     * Return to the sign-in page with the message on the identifier.
     */
    protected function sendSignInRefused(Request $request, string $message): RedirectResponse
    {
        return to_route('login')
            ->withErrors(['identifier' => $message])
            ->onlyInput('identifier');
    }
}
