<?php

use ClaudioDekker\Keystone\BootChecks;
use ClaudioDekker\Keystone\Exceptions\Misconfigured;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\StoredCredential;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Totp\Totp;
use ClaudioDekker\Keystone\Totp\TotpSecret;
use ClaudioDekker\Keystone\Totp\TotpType;

function totpBootFailures(): array
{
    try {
        (new BootChecks(app(CredentialTypes::class)))->check();
    } catch (Misconfigured $e) {
        return $e->failures;
    }

    return [];
}

it('boots with a window of any whole number of steps', function (int $windowSteps) {
    config(['keystone-totp.window_steps' => $windowSteps]);

    expect(totpBootFailures())->toBe([]);
})->with(['none' => 0, 'the default' => 1, 'a wide one' => 10]);

it('refuses to boot with a window that is negative or not a whole number', function (mixed $windowSteps) {
    config(['keystone-totp.window_steps' => $windowSteps]);

    expect(totpBootFailures())->toBe(['keystone-totp.window_steps must be a whole number of at least 0.']);
})->with(['negative' => -1, 'a fraction' => 1.5, 'a string' => '1', 'null' => null]);

it('proves a code by moving the credential on to the code\'s step, the same way for every request that read it first', function () {
    $this->freezeSecond();
    $credential = new StoredCredential(1, identifier: null, secret: (new TotpSecret('12345678901234567890', lastStep: null))->toStored(), label: null);
    $now = (new Totp)->stepAt(now()->getTimestamp());
    $input = ['code' => (new Totp)->code('12345678901234567890', $now)];

    $first = (new TotpType)->verify(Surface::CHALLENGE, $input, [$credential]);
    $second = (new TotpType)->verify(Surface::CHALLENGE, $input, [$credential]);

    expect($first->proven)->toBeTrue()
        ->and(TotpSecret::fromStored((string) $first->advancedSecret)->lastStep)->toBe($now)
        ->and($second->advancedSecret)->toBe($first->advancedSecret);
});
