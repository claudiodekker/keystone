<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers;

use ClaudioDekker\Keystone\Http\Controllers\CredentialEnrollmentController as Controller;
use ClaudioDekker\Keystone\Http\PageValues\EnrollmentFormPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CredentialEnrollmentController extends Controller
{
    protected function sendCredentialEnrollmentForm(Request $request, EnrollmentFormPage $page): JsonResponse
    {
        return response()->json(['type' => $page->type, 'shape' => $page->shape, 'ceremony' => $page->ceremony, 'status' => $page->status]);
    }

    protected function sendCredentialEnrollmentNotStarted(Request $request, string $type, string $message): RedirectResponse
    {
        return to_route('security')->withErrors([$type => $message]);
    }

    protected function sendCredentialEnrollmentRefused(Request $request, string $type, string $message): RedirectResponse
    {
        return to_route('security.enroll', ['type' => $type])->withErrors([$type => $message]);
    }

    protected function sendCredentialEnrollmentExpired(Request $request, string $type): RedirectResponse
    {
        return to_route('security.enroll', ['type' => $type]);
    }

    protected function sendCredentialEnrolled(Request $request): RedirectResponse
    {
        return to_route('security');
    }

    protected function sendCredentialEnrollmentCancelled(Request $request): RedirectResponse
    {
        return to_route('security');
    }
}
