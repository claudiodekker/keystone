<?php

use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Sleep;

it("refuses a suspended account's right secret exactly like a wrong one", function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    Artisan::call('keystone:suspend', ['user' => $user->getAuthIdentifier(), '--operator' => 'margaret']);

    $wrongSecret = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    $this->assertSignInRefused($wrongSecret, __('keystone::messages.credentials-rejected'));

    $this->switchToFreshBrowser();
    $rightSecret = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignInRefused($rightSecret, __('keystone::messages.credentials-rejected'));
    $this->assertIndistinguishable($wrongSecret, $rightSecret);
    Sleep::assertSequence([Sleep::for(300)->milliseconds(), Sleep::for(300)->milliseconds()]);
});
