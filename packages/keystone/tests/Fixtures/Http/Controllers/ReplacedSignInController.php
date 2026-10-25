<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers;

use ClaudioDekker\Keystone\Session\SessionState;
use ClaudioDekker\Keystone\SignIn\SignIn;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReplacedSignInController extends SignInController
{
    /**
     * Answer the page without Keystone's show().
     */
    public function show(Request $request, SessionState $session): Response|Responsable
    {
        return response('replaced');
    }

    /**
     * Answer the submit without Keystone's store().
     */
    public function store(Request $request, string $type, SessionState $session, SignIn $signIn): Response|Responsable
    {
        return response('replaced');
    }
}
