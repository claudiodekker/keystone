<?php

namespace App\Http\Controllers\Auth;

use ClaudioDekker\Keystone\Http\Controllers\EnrollmentController as Controller;
use ClaudioDekker\Keystone\Http\PageValues\EnrollmentFormPage;
use ClaudioDekker\Keystone\Http\PageValues\EnrollmentPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EnrollmentController extends Controller
{
    /**
     * Respond with the page listing the types the account can enroll, kept encrypted in the browser's history.
     */
    protected function sendEnrollmentPage(Request $request, EnrollmentPage $page): Response
    {
        Inertia::encryptHistory();

        return Inertia::render('auth/Enrollment', [
            'types' => $page->types,
            'preselect' => $page->preselect,
            'origin' => $page->origin,
        ]);
    }

    /**
     * Respond with the type's enrollment form, kept encrypted in the browser's history.
     */
    protected function sendEnrollmentForm(Request $request, EnrollmentFormPage $page): Response
    {
        Inertia::encryptHistory();

        return Inertia::render('auth/EnrollmentForm', [
            'type' => $page->type,
            'shape' => $page->shape,
            'ceremony' => $page->ceremony,
            'status' => $page->status,
            'held' => $page->held,
            'origin' => $page->origin,
        ]);
    }

    /**
     * Respond to a type whose enrollment couldn't start, with the message for the type on the enrollment page.
     */
    protected function sendEnrollmentNotStarted(Request $request, string $type, string $message): RedirectResponse
    {
        return to_route('login.enrollment')->withErrors([$type => $message]);
    }

    /**
     * Respond to a refused enrollment answer, with the message for the credential type's field.
     */
    protected function sendEnrollmentRefused(Request $request, string $type, string $message): RedirectResponse
    {
        return to_route('login.enrollment.start', ['type' => $type])->withErrors([$type => $message]);
    }

    /**
     * Respond to an answer whose ceremony expired, sending the user back to start the type's enrollment afresh.
     */
    protected function sendEnrollmentExpired(Request $request, string $type): RedirectResponse
    {
        return to_route('login.enrollment.start', ['type' => $type]);
    }

    /**
     * Respond to a held account that still owes recovery codes, sending the user on to save them.
     */
    protected function sendRecoveryCodesOwed(Request $request): RedirectResponse
    {
        return to_route('login.recovery-codes');
    }

    /**
     * Respond to a completed enrollment, sending the signed-in user on to the intended URL.
     */
    protected function sendEnrollmentCompleted(Request $request, string $intendedUrl): RedirectResponse
    {
        return redirect($intendedUrl);
    }

    /**
     * Respond to a cancelled enrollment on the sign-in page.
     */
    protected function sendEnrollmentCancelled(Request $request): RedirectResponse
    {
        return to_route('login');
    }
}
