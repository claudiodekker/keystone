<?php

use ClaudioDekker\Keystone\Methods\Surface;

it('gives the contract AppTests the test support of the installed password type', function () {
    $names = collect($this->supportsFor(Surface::SIGN_IN))
        ->map(fn ($support) => $support->type()->name())
        ->all();

    expect($names)->toContain('password');
});
