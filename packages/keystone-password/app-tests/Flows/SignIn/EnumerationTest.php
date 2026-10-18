<?php

use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Password\AppTests\PasswordSupport;
use Illuminate\Support\Sleep;

it('answers an account without a password exactly like a wrong password', function () {
    $ada = $this->createUser();
    $this->addVerifiedAddress($ada, 'ada@example.com');
    $support = (new PasswordSupport)->arrangeCredential(Surface::SIGN_IN, $ada);
    $grace = $this->createUser();
    $this->addVerifiedAddress($grace, 'grace@example.com');

    $wrong = $this->post(route('login.submit', 'password'), ['identifier' => 'ada@example.com', ...$support->rejectedProof(Surface::SIGN_IN)]);

    $this->assertSignInRefused($wrong, __('keystone::messages.credentials-rejected'));

    $without = $this->flushSession()->post(route('login.submit', 'password'), ['identifier' => 'grace@example.com', ...$support->validProof(Surface::SIGN_IN, $grace)]);

    $this->assertSignInRefused($without, __('keystone::messages.credentials-rejected'));
    $this->assertIndistinguishable($wrong, $without);
    Sleep::assertSequence([Sleep::for(300)->milliseconds(), Sleep::for(300)->milliseconds()]);
});

it('answers an account whose stored secret is not a hash exactly like a wrong password', function (?string $stored) {
    $ada = $this->createUser();
    $this->addVerifiedAddress($ada, 'ada@example.com');
    $support = (new PasswordSupport)->arrangeCredential(Surface::SIGN_IN, $ada);
    $grace = $this->createUser();
    $this->addVerifiedAddress($grace, 'grace@example.com');
    $support->arrangeHash($grace, $stored);

    $wrong = $this->post(route('login.submit', 'password'), ['identifier' => 'ada@example.com', ...$support->rejectedProof(Surface::SIGN_IN)]);

    $this->assertSignInRefused($wrong, __('keystone::messages.credentials-rejected'));

    $unusable = $this->flushSession()->post(route('login.submit', 'password'), ['identifier' => 'grace@example.com', ...$support->validProof(Surface::SIGN_IN, $grace)]);

    $this->assertSignInRefused($unusable, __('keystone::messages.credentials-rejected'));
    $this->assertIndistinguishable($wrong, $unusable);
    Sleep::assertSequence([Sleep::for(300)->milliseconds(), Sleep::for(300)->milliseconds()]);
})->with([
    'no secret' => [null],
    'plain text' => ['correct horse battery staple'],
    'an unknown algorithm' => ['$1$salt$qJH7.N4xYta3aEG/dfqo/0'],
]);
