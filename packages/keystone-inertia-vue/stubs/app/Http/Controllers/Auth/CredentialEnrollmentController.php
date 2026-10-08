<?php

namespace App\Http\Controllers\Auth;

use ClaudioDekker\Keystone\Http\Controllers\CredentialEnrollmentController as Controller;
use ClaudioDekker\Keystone\Http\PageValues\EnrollmentFormPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CredentialEnrollmentController extends Controller
{
    /**
     * Respond with the type's enrollment form in the security settings, kept encrypted in the browser's history.
     */
    protected function sendCredentialEnrollmentForm(Request $request, EnrollmentFormPage $page): Response
    {
        Inertia::encryptHistory();

        return Inertia::render('settings/CredentialEnrollment', [
            'type' => $page->type,
            'shape' => $page->shape,
            'ceremony' => $page->ceremony,
            'status' => $page->status,
        ]);
    }

    /**
     * Respond to a type whose enrollment couldn't start, with the message for the type on the security page.
     */
    protected function sendCredentialEnrollmentNotStarted(Request $request, string $type, string $message): RedirectResponse
    {
        return to_route('security')->withErrors([$type => $message]);
    }

    /**
     * Respond to a refused enrollment answer, with the message for the credential type's field.
     */
    protected function sendCredentialEnrollmentRefused(Request $request, string $type, string $message): RedirectResponse
    {
        return to_route('security.enroll', ['type' => $type])->withErrors([$type => $message]);
    }

    /**
     * Respond to an answer whose ceremony expired, sending the user back to start the type's enrollment afresh.
     */
    protected function sendCredentialEnrollmentExpired(Request $request, string $type): RedirectResponse
    {
        return to_route('security.enroll', ['type' => $type]);
    }

    /**
     * Respond to a stored credential, sending the user to the security page.
     */
    protected function sendCredentialEnrolled(Request $request): RedirectResponse
    {
        return to_route('security');
    }

    /**
     * Respond to a cancelled enrollment, sending the user to the security page.
     */
    protected function sendCredentialEnrollmentCancelled(Request $request): RedirectResponse
    {
        return to_route('security');
    }
}
