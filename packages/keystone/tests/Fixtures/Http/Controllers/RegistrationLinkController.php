<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers;

use ClaudioDekker\Keystone\Http\Controllers\RegistrationLinkController as Controller;
use ClaudioDekker\Keystone\Http\PageValues\EmailedLinkPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RegistrationLinkController extends Controller
{
    /**
     * Send the link's step as JSON.
     */
    protected function sendRegistrationLinkPage(Request $request, EmailedLinkPage $page): JsonResponse
    {
        return response()->json(['page' => 'emailed-link', 'action' => $page->action]);
    }

    /**
     * Send the user on to finish registering.
     */
    protected function sendRegistrationLinkConsumed(Request $request): RedirectResponse
    {
        return to_route('register.finish');
    }

    /**
     * Send the user on to "link expired".
     */
    protected function sendRegistrationLinkExpired(Request $request): RedirectResponse
    {
        return to_route('register.link-expired');
    }

    /**
     * Send the "link expired" step as JSON.
     */
    protected function sendRegistrationLinkExpiredPage(Request $request): JsonResponse
    {
        return response()->json(['page' => 'register-link-expired']);
    }
}
