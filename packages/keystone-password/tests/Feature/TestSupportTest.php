<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Password\AppTests\Support\PasswordTypeSupport;

pest()->extend(AppTestCase::class);

it('finds the test support in the package\'s AppTests without a binding', function () {
    $supports = $this->supportsFor(Surface::SIGN_IN);

    expect(array_map(fn ($support) => $support::class, $supports))->toContain(PasswordTypeSupport::class);
});
