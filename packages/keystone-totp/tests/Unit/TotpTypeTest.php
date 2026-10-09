<?php

use ClaudioDekker\Keystone\BootChecks;
use ClaudioDekker\Keystone\Exceptions\Misconfigured;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\StoredCredential;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Totp\Totp;
use ClaudioDekker\Keystone\Totp\TotpSecret;
use ClaudioDekker\Keystone\Totp\TotpType;
use ParagonIE\ConstantTime\Base32;

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

describe('enrollment', function () {
    it('makes a new 160-bit key, shown in Base32 and as an otpauth URI naming the app and the account', function () {
        config(['app.name' => 'Acme & Co']);

        $initiation = (new TotpType)->initiate(Surface::ENROLLMENT, 'jane@example.com');
        $secret = TotpSecret::fromStored($initiation->ceremony);

        expect(strlen($secret->key))->toBe(20)
            ->and($secret->lastStep)->toBeNull()
            ->and($initiation->page['key'])->toBe(Base32::encodeUpperUnpadded($secret->key))
            ->and($initiation->page['uri'])->toBe("otpauth://totp/Acme%20%26%20Co:jane%40example.com?secret={$initiation->page['key']}&issuer=Acme%20%26%20Co&algorithm=SHA1&digits=6&period=30");
    });

    it('makes a different key every time', function () {
        $first = (new TotpType)->initiate(Surface::ENROLLMENT, 'jane@example.com');
        $second = (new TotpType)->initiate(Surface::ENROLLMENT, 'jane@example.com');

        expect($first->ceremony)->not->toBe($second->ceremony);
    });

    it('enrolls the key once a code it makes is typed back, accepting nothing from that step again', function () {
        $this->freezeSecond();
        $ceremony = (new TotpSecret('12345678901234567890', lastStep: null))->toStored();
        $now = (new Totp)->stepAt(now()->getTimestamp());

        $proof = (new TotpType)->verify(Surface::ENROLLMENT, ['code' => (new Totp)->code('12345678901234567890', $now)], [], $ceremony);

        expect($proof->proven)->toBeTrue()
            ->and(TotpSecret::fromStored((string) $proof->enrolled?->secret))->toEqual(new TotpSecret('12345678901234567890', lastStep: $now));
    });

    it('refuses a code the new key doesn\'t make', function () {
        $ceremony = (new TotpSecret('12345678901234567890', lastStep: null))->toStored();

        $proof = (new TotpType)->verify(Surface::ENROLLMENT, ['code' => 'not-a-code'], [], $ceremony);

        expect($proof->proven)->toBeFalse()
            ->and($proof->reason)->toBe('totp.mismatch')
            ->and($proof->enrolled)->toBeNull();
    });

    it('starts no ceremony at the challenge', function () {
        (new TotpType)->initiate(Surface::CHALLENGE, 'jane@example.com');
    })->throws(LogicException::class);
});
