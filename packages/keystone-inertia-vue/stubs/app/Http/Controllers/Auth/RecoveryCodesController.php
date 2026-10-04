<?php

namespace App\Http\Controllers\Auth;

use ClaudioDekker\Keystone\Http\Controllers\RecoveryCodesController as Controller;
use ClaudioDekker\Keystone\Http\PageValues\RecoveryCodesPage;
use ClaudioDekker\Keystone\Methods\RecoveryCodeType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RecoveryCodesController extends Controller
{
    /**
     * Respond with the page showing the staged recovery codes, kept encrypted in the browser's history.
     */
    protected function sendRecoveryCodesPage(Request $request, RecoveryCodesPage $page): Response
    {
        Inertia::encryptHistory();

        return Inertia::render('auth/RecoveryCodes', [
            'codes' => $page->codes,
        ]);
    }

    /**
     * Respond to a code typed back that isn't one of the staged set, with the message for its field.
     */
    protected function sendRecoveryCodeRefused(Request $request, string $message): RedirectResponse
    {
        return to_route('login.recovery-codes')->withErrors([RecoveryCodeType::FIELD => $message]);
    }

    /**
     * Respond to a held account that owes a second factor before its recovery codes, sending the user on to enroll it.
     */
    protected function sendSecondFactorOwed(Request $request): RedirectResponse
    {
        return to_route('login.enrollment');
    }

    /**
     * Respond to saved recovery codes, sending the signed-in user on to the intended URL.
     */
    protected function sendRecoveryCodesSaved(Request $request, string $intendedUrl): RedirectResponse
    {
        return redirect($intendedUrl);
    }
}
