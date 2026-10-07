<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers;

use ClaudioDekker\Keystone\Http\Controllers\SudoController as Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SudoController extends Controller
{
    protected function sendSudoEnded(Request $request): RedirectResponse
    {
        return back(fallback: '/');
    }
}
