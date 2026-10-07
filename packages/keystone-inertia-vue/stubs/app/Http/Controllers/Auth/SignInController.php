<?php

namespace App\Http\Controllers\Auth;

use ClaudioDekker\Keystone\Http\Controllers\SignInController as Controller;
use ClaudioDekker\Keystone\Http\PageValues\SignInPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SignInController extends Controller
{
    /**
     * Respond with the sign-in page, filled with the identifier a refused attempt flashed back and kept encrypted in the browser's history.
     */
    protected function sendSignInPage(Request $request, SignInPage $page): Response
    {
        Inertia::encryptHistory();

        return Inertia::render('auth/Login', [
            'types' => $page->types,
            'status' => $page->status,
            'identifier' => $request->old(self::IDENTIFIER),
            'rememberOffered' => $page->rememberOffered,
        ]);
    }

    /**
     * Respond to a refused sign-in, with the message for the identifier field.
     */
    protected function sendSignInRefused(Request $request, string $message): RedirectResponse
    {
        return to_route('login')->withErrors([self::IDENTIFIER => $message]);
    }

    /**
     * Respond to a sign-in held for a challenge, sending the user on to it.
     */
    protected function sendChallengeOwed(Request $request): RedirectResponse
    {
        return to_route('login.challenge');
    }

    /**
     * Respond to a sign-in held for the enrollment the account owes, sending the user on to it.
     */
    protected function sendEnrollmentOwed(Request $request): RedirectResponse
    {
        return to_route('login.enrollment');
    }

    /**
     * Respond to a completed sign-in, sending the user on to the intended URL.
     */
    protected function sendSignedIn(Request $request, string $intendedUrl): RedirectResponse
    {
        return redirect($intendedUrl);
    }
}
