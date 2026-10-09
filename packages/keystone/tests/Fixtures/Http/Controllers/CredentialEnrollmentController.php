<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers;

use ClaudioDekker\Keystone\Http\Controllers\CredentialEnrollmentController as Controller;
use ClaudioDekker\Keystone\Http\PageValues\EnrollmentFormPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CredentialEnrollmentController extends Controller
{
    /**
     * Send the enrollment form as JSON.
     */
    protected function sendCredentialEnrollmentForm(Request $request, EnrollmentFormPage $page): JsonResponse
    {
        return response()->json(['type' => $page->type, 'shape' => $page->shape, 'ceremony' => $page->ceremony, 'status' => $page->status, 'held' => $page->held]);
    }

    /**
     * Send the user back to the security page with the reason the ceremony didn't start.
     */
    protected function sendCredentialEnrollmentNotStarted(Request $request, string $type, string $message): RedirectResponse
    {
        return to_route('security')->withErrors([$type => $message]);
    }

    /**
     * Send the user back to the type's step with the refusal.
     */
    protected function sendCredentialEnrollmentRefused(Request $request, string $type, string $message): RedirectResponse
    {
        return to_route('security.enroll', ['type' => $type])->withErrors([$type => $message]);
    }

    /**
     * Send the user back to the type's step to start a new ceremony.
     */
    protected function sendCredentialEnrollmentExpired(Request $request, string $type): RedirectResponse
    {
        return to_route('security.enroll', ['type' => $type]);
    }

    /**
     * Send the user to the security page once the credential is stored.
     */
    protected function sendCredentialEnrolled(Request $request): RedirectResponse
    {
        return to_route('security');
    }

    /**
     * Send the user to the security page once the ceremony is closed.
     */
    protected function sendCredentialEnrollmentCancelled(Request $request): RedirectResponse
    {
        return to_route('security');
    }
}
