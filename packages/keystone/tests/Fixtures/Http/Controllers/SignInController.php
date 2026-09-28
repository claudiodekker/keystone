<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers;

use ClaudioDekker\Keystone\Http\Controllers\SignInController as Controller;
use ClaudioDekker\Keystone\Http\PageValues\SignInPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SignInController extends Controller
{
    protected function sendSignInPage(Request $request, SignInPage $page): JsonResponse
    {
        return response()->json(['types' => $page->types, 'status' => $page->status]);
    }

    protected function sendSignInRefused(Request $request, string $message): RedirectResponse
    {
        return to_route('login')->withErrors([self::IDENTIFIER => $message]);
    }

    protected function sendSignedIn(Request $request, string $intendedUrl): RedirectResponse
    {
        return redirect($intendedUrl);
    }
}
