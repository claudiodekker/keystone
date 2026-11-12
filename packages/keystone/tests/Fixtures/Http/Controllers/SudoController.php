<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers;

use ClaudioDekker\Keystone\Http\Controllers\SudoController as BaseSudoController;
use ClaudioDekker\Keystone\Http\Pages\SudoPage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SudoController extends BaseSudoController
{
    /**
     * Render the sudo page with the page value's fields as view data.
     */
    protected function sendSudoPage(Request $request, SudoPage $page): Response
    {
        return response()->view('fixtures::sudo', [
            'preselected' => $page->preselected,
            'types' => $page->types,
        ]);
    }

    /**
     * Send a session without a sudo-in-progress on to where it was going.
     */
    protected function sendNoSudoInProgress(Request $request, string $redirectTo): Response
    {
        return redirect($redirectTo);
    }

    /**
     * Return to the sudo page with the message on the type's field, flashing back all input as a careless app would.
     */
    protected function sendSudoRefused(Request $request, string $field, string $message): Response
    {
        return to_route('sudo')
            ->withErrors([$field => $message])
            ->withInput();
    }

    /**
     * Send the user on to where they were going once sudo is granted.
     */
    protected function sendSudoGranted(Request $request, string $redirectTo): Response
    {
        return redirect($redirectTo);
    }
}
