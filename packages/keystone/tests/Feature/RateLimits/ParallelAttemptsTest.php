<?php

// Fault injection through the fake type: no installed type holds a request open while others arrive, so no AppTest can.

use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\SignIn\SignIn;

it('gives parallel wrong answers no more verify calls than the allowance', function () {
    $type = app(CredentialTypes::class)->find('form', Surface::SIGN_IN);
    $attempt = fn () => rescue(fn () => $this->app->make(SignIn::class)->attempt($type, [
        'identifier' => 'ada@example.com',
        'secret' => 'wrong horse',
    ]), report: false);

    $type->duringNextVerify(function () use ($attempt) {
        foreach (range(1, 25) as $i) {
            $attempt();
        }
    });

    $attempt();

    expect($type->verifications())->toHaveCount(config('keystone.rate_limits.failed_attempts_per_hour'));
});
