<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers;

use ClaudioDekker\Keystone\Http\Controllers\SignInController as Controller;
use ClaudioDekker\Keystone\Http\PageValues\SignInPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SignInController extends Controller
{
    protected function sendSignInPage(Request $request, SignInPage $page): Response
    {
        app()->instance(SignInPage::class, $page);

        return response('The sign-in page.');
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
