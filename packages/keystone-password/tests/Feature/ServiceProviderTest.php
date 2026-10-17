<?php

use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Password\PasswordType;

it('registers password as a plain instance it never binds', function () {
    $type = Keystone::credentialTypes()->find('password', Surface::SIGN_IN);

    expect($type)->toBeInstanceOf(PasswordType::class)
        ->and($this->app->bound(PasswordType::class))->toBeFalse();
});
