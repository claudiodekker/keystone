<?php

use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;

it('never trims the password fields, even when the app trims every other field', function () {
    $request = Request::create('/', 'POST', [
        'password' => '  a  ',
        'current_password' => '  b  ',
        'password_confirmation' => '  c  ',
        'identifier' => '  d  ',
    ]);
    $middleware = new class extends TrimStrings
    {
        protected $except = [];
    };

    $middleware->handle($request, fn (Request $request) => $request);

    expect($request->all())->toBe([
        'password' => '  a  ',
        'current_password' => '  b  ',
        'password_confirmation' => '  c  ',
        'identifier' => 'd',
    ]);
});
