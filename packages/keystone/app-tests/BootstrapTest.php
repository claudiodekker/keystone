<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\KeystoneUser;

pest()->extend(AppTestCase::class);

test('the user model has a factory', function () {
    expect($this->userFactory()->make())->toBeInstanceOf(KeystoneUser::class);
});
