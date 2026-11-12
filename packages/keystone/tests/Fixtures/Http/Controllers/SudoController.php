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
}
