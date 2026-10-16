<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers;

use ClaudioDekker\Keystone\Http\Controllers\SignInController as BaseSignInController;
use ClaudioDekker\Keystone\Http\Pages\SignInPage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answer every sign-in outcome the way Keystone's default assertions expect.
 */
class SignInController extends BaseSignInController
{
    /**
     * Render the sign-in page with the page value's fields as view data.
     */
    protected function sendSignInPage(Request $request, SignInPage $page): Response
    {
        return response()->view('fixtures::sign-in', [
            'types' => $page->types,
        ]);
    }

    /**
     * Send a signed-in user home.
     */
    protected function sendAlreadySignedIn(Request $request): Response
    {
        return redirect('/');
    }

    /**
     * Send the user on to where the sign-in lands.
     */
    protected function sendSignedIn(Request $request, string $redirectTo): Response
    {
        return redirect($redirectTo);
    }

    /**
     * Return to the sign-in page with the message on the identifier.
     */
    protected function sendSignInRefused(Request $request, string $message): Response
    {
        return to_route('login')
            ->withErrors(['identifier' => $message])
            ->onlyInput('identifier');
    }
}
