<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers;

use ClaudioDekker\Keystone\Http\Controllers\RegistrationController as Controller;
use ClaudioDekker\Keystone\Http\PageValues\RegisterPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RegistrationController extends Controller
{
    /**
     * Send the register page as JSON.
     */
    protected function sendRegistrationPage(Request $request, RegisterPage $page): JsonResponse
    {
        return response()->json(['page' => 'register', 'status' => $page->status]);
    }

    /**
     * Send the user on to "link sent".
     */
    protected function sendRegistrationLinkSent(Request $request): RedirectResponse
    {
        return to_route('register.link-sent');
    }

    /**
     * Send the "link sent" step as JSON.
     */
    protected function sendRegistrationLinkSentPage(Request $request): JsonResponse
    {
        return response()->json(['page' => 'register-link-sent']);
    }
}
