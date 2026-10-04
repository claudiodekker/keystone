<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers;

use ClaudioDekker\Keystone\Http\Controllers\EnrollmentController as Controller;
use ClaudioDekker\Keystone\Http\PageValues\EnrollmentFormPage;
use ClaudioDekker\Keystone\Http\PageValues\EnrollmentPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EnrollmentController extends Controller
{
    protected function sendEnrollmentPage(Request $request, EnrollmentPage $page): JsonResponse
    {
        return response()->json(['types' => $page->types, 'preselect' => $page->preselect, 'origin' => $page->origin]);
    }

    protected function sendEnrollmentForm(Request $request, EnrollmentFormPage $page): JsonResponse
    {
        return response()->json(['type' => $page->type, 'shape' => $page->shape, 'ceremony' => $page->ceremony, 'status' => $page->status]);
    }

    protected function sendEnrollmentNotStarted(Request $request, string $type, string $message): RedirectResponse
    {
        return to_route('login.enrollment')->withErrors([$type => $message]);
    }

    protected function sendEnrollmentRefused(Request $request, string $type, string $message): RedirectResponse
    {
        return to_route('login.enrollment.start', ['type' => $type])->withErrors([$type => $message]);
    }

    protected function sendEnrollmentExpired(Request $request, string $type): RedirectResponse
    {
        return to_route('login.enrollment.start', ['type' => $type]);
    }

    protected function sendRecoveryCodesOwed(Request $request): RedirectResponse
    {
        return to_route('login.recovery-codes');
    }

    protected function sendEnrollmentCompleted(Request $request, string $intendedUrl): RedirectResponse
    {
        return redirect($intendedUrl);
    }

    protected function sendEnrollmentCancelled(Request $request): RedirectResponse
    {
        return to_route('login');
    }
}
