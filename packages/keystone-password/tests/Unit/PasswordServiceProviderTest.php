<?php

use ClaudioDekker\Keystone\Password\PasswordServiceProvider;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;

it('keeps the other minimum length when the app publishes only one', function () {
    app()->instance('config_loaded_from_cache', false);
    config(['keystone-password' => ['min_length' => ['second_factor_optional' => 20]]]);

    (new PasswordServiceProvider(app()))->register();

    expect(config('keystone-password.min_length'))->toBe(['second_factor_required' => 8, 'second_factor_optional' => 20])
        ->and(config('keystone-password.context_words'))->toBe([]);
});

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
