<?php

use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Password\PasswordType;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;

it('registers password as a plain instance it never binds', function () {
    $type = Keystone::credentialTypes()->find('password', Surface::SIGN_IN);

    expect($type)->toBeInstanceOf(PasswordType::class)
        ->and($this->app->bound(PasswordType::class))->toBeFalse();
});

it('never trims a password field, even when the app drops them from TrimStrings', function (string $field) {
    $middleware = new class extends TrimStrings
    {
        protected $except = [];
    };

    $request = Request::create('/', 'POST', [$field => '  odd horse  ', 'identifier' => '  ada  ']);

    $middleware->handle($request, fn ($request) => $request);

    expect($request->input($field))->toBe('  odd horse  ')
        ->and($request->input('identifier'))->toBe('ada');
})->with([
    'current_password' => ['current_password'],
    'password' => ['password'],
    'password_confirmation' => ['password_confirmation'],
]);
