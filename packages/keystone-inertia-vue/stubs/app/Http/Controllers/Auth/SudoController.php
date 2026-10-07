<?php

namespace App\Http\Controllers\Auth;

use ClaudioDekker\Keystone\Http\Controllers\SudoController as Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SudoController extends Controller
{
    /**
     * Respond to an ended sudo, sending the user back to the page they ended it from.
     */
    protected function sendSudoEnded(Request $request): RedirectResponse
    {
        return back(fallback: '/');
    }
}
