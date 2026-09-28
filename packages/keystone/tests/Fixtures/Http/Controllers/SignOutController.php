<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers;

use ClaudioDekker\Keystone\Http\Controllers\SignOutController as Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SignOutController extends Controller
{
    protected function sendSignedOut(Request $request): RedirectResponse
    {
        return to_route('login');
    }
}
