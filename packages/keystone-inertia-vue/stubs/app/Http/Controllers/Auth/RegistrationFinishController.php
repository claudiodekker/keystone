<?php

namespace App\Http\Controllers\Auth;

use ClaudioDekker\Keystone\Http\Controllers\RegistrationFinishController as Controller;
use ClaudioDekker\Keystone\Http\PageValues\RegistrationFinishPage;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RegistrationFinishController extends Controller
{
    /**
     * Respond with the page that finishes the registration, kept encrypted in the browser's history.
     */
    protected function sendRegistrationFinishPage(Request $request, RegistrationFinishPage $page): Response
    {
        Inertia::encryptHistory();

        return Inertia::render('auth/RegisterFinish', [
            'address' => $page->address,
            'types' => $page->types,
            'status' => $page->status,
        ]);
    }
}
