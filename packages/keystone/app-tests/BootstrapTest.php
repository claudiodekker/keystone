<?php

use ClaudioDekker\Keystone\Methods\Surface;

it("creates users from the app's own factory", function () {
    $user = $this->createUser();

    expect($user->getAuthIdentifier())->not->toBeNull();
});

it('has an installed credential type that can sign in', function () {
    $support = $this->supportFor(Surface::SIGN_IN);

    expect($support->type()->initiateShape(Surface::SIGN_IN))->not->toBeNull();
});
