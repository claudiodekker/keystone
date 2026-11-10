<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers;

use ClaudioDekker\Keystone\Http\Controllers\EnrollmentController as BaseEnrollmentController;
use ClaudioDekker\Keystone\Http\Pages\EnrollmentPage;
use ClaudioDekker\Keystone\Http\Pages\RecoveryCodesPage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnrollmentController extends BaseEnrollmentController
{
    /**
     * Render the second-factor step with the page value's fields as view data.
     */
    protected function sendEnrollmentPage(Request $request, EnrollmentPage $page): Response
    {
        return response()->view('fixtures::enrollment', [
            'preselected' => $page->preselected,
            'types' => $page->types,
        ]);
    }

    /**
     * Render the recovery-code step with the staged codes as view data.
     */
    protected function sendRecoveryCodesPage(Request $request, RecoveryCodesPage $page): Response
    {
        return response()->view('fixtures::recovery-codes', [
            'codes' => $page->codes,
        ]);
    }

    /**
     * Send a session without a pending sign-in to sign in.
     */
    protected function sendNoPendingSignIn(Request $request): Response
    {
        return to_route('login');
    }

    /**
     * Return to enrollment with the message on the type's field, flashing back all input as a careless app would.
     */
    protected function sendEnrollmentRefused(Request $request, string $field, string $message): Response
    {
        return to_route('login.enrollment')
            ->withErrors([$field => $message])
            ->withInput();
    }

    /**
     * Return to enrollment with the message on the type's field, flashing back all input as a careless app would.
     */
    protected function sendEnrollmentExpired(Request $request, string $field, string $message): Response
    {
        return to_route('login.enrollment')
            ->withErrors([$field => $message])
            ->withInput();
    }

    /**
     * Return to enrollment for the recovery codes.
     */
    protected function sendSecondFactorEnrolled(Request $request): Response
    {
        return to_route('login.enrollment');
    }

    /**
     * Send the user on to where the sign-in lands.
     */
    protected function sendSignedIn(Request $request, string $redirectTo): Response
    {
        return redirect($redirectTo);
    }

    /**
     * Send the user to sign in again.
     */
    protected function sendEnrollmentCancelled(Request $request): Response
    {
        return to_route('login');
    }
}
