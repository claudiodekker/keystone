<?php

use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Support\Facades\Auth;

it('signs out a session its absolute lifetime after the sign-in, and tells the user why', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    Auth::forgetGuards();
    $this->travel($this->absoluteLifetimeSeconds())->seconds();

    $response = $this->get(route('login'));

    $this->assertSignInPage($response, ['status' => __('keystone::messages.session-expired')]);
});
