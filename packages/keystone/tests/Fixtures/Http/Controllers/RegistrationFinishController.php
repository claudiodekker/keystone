<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers;

use ClaudioDekker\Keystone\Http\Controllers\RegistrationFinishController as Controller;
use ClaudioDekker\Keystone\Http\PageValues\RegistrationFinishPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RegistrationFinishController extends Controller
{
    /**
     * Send the finish page as JSON.
     */
    protected function sendRegistrationFinishPage(Request $request, RegistrationFinishPage $page): JsonResponse
    {
        return response()->json(['page' => 'register-finish', 'address' => $page->address, 'types' => $page->types, 'status' => $page->status]);
    }

    protected function sendRegistered(Request $request, string $intendedUrl): RedirectResponse
    {
        return redirect($intendedUrl);
    }

    protected function sendRegistrationEnrollmentOwed(Request $request): RedirectResponse
    {
        return to_route('login.enrollment');
    }

    protected function sendRegistrationRefused(Request $request, string $type, string $message): RedirectResponse
    {
        return to_route('register.finish')->withErrors([$type => $message]);
    }

    protected function sendAddressTaken(Request $request): RedirectResponse
    {
        return to_route('login');
    }

    protected function sendRegistrationBarred(Request $request, string $message): RedirectResponse
    {
        return to_route('login')->withErrors(['identifier' => $message]);
    }

    protected function sendRegistrationCancelled(Request $request): RedirectResponse
    {
        return to_route('register');
    }
}
