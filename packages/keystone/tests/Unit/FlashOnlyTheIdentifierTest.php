<?php

use ClaudioDekker\Keystone\Http\Middleware\FlashOnlyTheIdentifier;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

it('writes no flashed input when the session holds none', function () {
    $session = $this->app['session.store'];
    $request = Request::create('/login');
    $request->setLaravelSession($session);

    (new FlashOnlyTheIdentifier)->handle($request, fn () => new Response);

    expect($session->has('_old_input'))->toBeFalse();
});
