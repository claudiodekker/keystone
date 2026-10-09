<?php

namespace App\Http\Controllers\Auth;

use ClaudioDekker\Keystone\Http\Controllers\RegistrationFinishController as Controller;
use ClaudioDekker\Keystone\Http\PageValues\RegistrationFinishPage;
use Illuminate\Http\RedirectResponse;
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

    /**
     * Respond to a registration that signed the new account in, sending the user on to the intended URL.
     */
    protected function sendRegistered(Request $request, string $intendedUrl): RedirectResponse
    {
        return redirect($intendedUrl);
    }

    /**
     * Respond to a registration held for the enrollment the new account owes, sending the user on to enroll it.
     */
    protected function sendRegistrationEnrollmentOwed(Request $request): RedirectResponse
    {
        return to_route('login.enrollment');
    }

    /**
     * Respond to a refused credential, with the message for the credential type's field.
     */
    protected function sendRegistrationRefused(Request $request, string $type, string $message): RedirectResponse
    {
        return to_route('register.finish')->withErrors([$type => $message]);
    }

    /**
     * Respond to an address another account came to hold on the sign-in page, which says it is already registered.
     */
    protected function sendAddressTaken(Request $request): RedirectResponse
    {
        return to_route('login');
    }

    /**
     * Respond to an account created barred from signing in on the sign-in page, with the message for the identifier field.
     */
    protected function sendRegistrationBarred(Request $request, string $message): RedirectResponse
    {
        return to_route('login')->withErrors([SignInController::IDENTIFIER => $message]);
    }
}
