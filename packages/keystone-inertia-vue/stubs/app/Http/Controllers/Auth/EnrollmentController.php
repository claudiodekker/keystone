<?php

namespace App\Http\Controllers\Auth;

use ClaudioDekker\Keystone\Http\Controllers\EnrollmentController as KeystoneEnrollmentController;
use ClaudioDekker\Keystone\Http\Pages\EnrollmentPage;
use ClaudioDekker\Keystone\Http\Pages\RecoveryCodesPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Answer every outcome of enrolling what a sign-in owes. Keystone runs the flow; this decides what users see.
 */
class EnrollmentController extends KeystoneEnrollmentController
{
    /**
     * Render the second-factor step, with the browser history encrypted, and the page value's fields as props.
     */
    protected function sendEnrollmentPage(Request $request, EnrollmentPage $page): Response
    {
        Inertia::encryptHistory();

        return Inertia::render('auth/Enrollment', [
            'preselected' => $page->preselected,
            'types' => $page->types,
        ]);
    }

    /**
     * Render the recovery-code step, with the browser history encrypted, and the staged codes as a prop.
     */
    protected function sendRecoveryCodesPage(Request $request, RecoveryCodesPage $page): Response
    {
        Inertia::encryptHistory();

        return Inertia::render('auth/RecoveryCodes', [
            'codes' => $page->codes,
        ]);
    }

    /**
     * Send a session without a pending sign-in to sign in.
     */
    protected function sendNoPendingSignIn(Request $request): RedirectResponse
    {
        return to_route('login');
    }

    /**
     * Return to enrollment with the message on the type's field, flashing back no input.
     */
    protected function sendEnrollmentRefused(Request $request, string $field, string $message): RedirectResponse
    {
        return to_route('login.enrollment')->withErrors([$field => $message]);
    }

    /**
     * Return to enrollment, which shows the ceremony that started afresh, with the message on the type's field.
     */
    protected function sendEnrollmentExpired(Request $request, string $field, string $message): RedirectResponse
    {
        return to_route('login.enrollment')->withErrors([$field => $message]);
    }

    /**
     * Return to enrollment for the recovery codes.
     */
    protected function sendSecondFactorEnrolled(Request $request): RedirectResponse
    {
        return to_route('login.enrollment');
    }

    /**
     * Send the user on to where the sign-in lands.
     */
    protected function sendSignedIn(Request $request, string $redirectTo): RedirectResponse
    {
        return redirect($redirectTo);
    }

    /**
     * Send the user to sign in again.
     */
    protected function sendEnrollmentCancelled(Request $request): RedirectResponse
    {
        return to_route('login');
    }
}
