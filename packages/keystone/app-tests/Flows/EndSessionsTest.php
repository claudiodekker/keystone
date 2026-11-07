<?php

use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;

it("signs out a session that signed in before the operator ended the account's sessions, on its next request", function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    Artisan::call('keystone:end-sessions', ['user' => $user->getAuthIdentifier(), '--operator' => 'grace']);

    Auth::forgetGuards();
    $response = $this->get(route('login'));

    $this->assertSignInPage($response);
});
