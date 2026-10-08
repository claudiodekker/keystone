<?php

namespace App\Http\Controllers\Auth;

use ClaudioDekker\Keystone\Http\Controllers\SecurityController as Controller;
use ClaudioDekker\Keystone\Http\PageValues\SecurityPage;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SecurityController extends Controller
{
    /**
     * Respond with the security page, kept encrypted in the browser's history.
     */
    protected function sendSecurityPage(Request $request, SecurityPage $page): Response
    {
        Inertia::encryptHistory();

        return Inertia::render('settings/Security', [
            'types' => $page->types,
            'leftovers' => $page->leftovers,
            'recoveryCodes' => $page->recoveryCodes,
            'recoveryCodesLow' => $page->recoveryCodesLow,
            'sudoEndsAt' => $page->sudoEndsAt,
            'status' => $page->status,
        ]);
    }
}
