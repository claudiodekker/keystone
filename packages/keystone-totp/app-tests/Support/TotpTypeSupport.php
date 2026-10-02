<?php

namespace ClaudioDekker\Keystone\Totp\AppTests\Support;

use ClaudioDekker\Keystone\AppTests\Support\CredentialTypeSupport;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Totp\Totp;
use ClaudioDekker\Keystone\Totp\TotpSecret;
use ClaudioDekker\Keystone\Totp\TotpType;
use Illuminate\Support\Facades\Date;

/**
 * @api
 */
class TotpTypeSupport implements CredentialTypeSupport
{
    /**
     * The key the arranged credential holds.
     */
    protected const string KEY = '12345678901234567890';

    /**
     * Get the name of the TOTP type.
     */
    public function type(): string
    {
        return 'totp';
    }

    /**
     * Arrange a TOTP credential holding the known key, at no step yet.
     */
    public function arrange(Surface $surface): array
    {
        $secret = new TotpSecret(self::KEY, lastStep: null);

        return ['identifier' => null, 'secret' => $secret->toStored(), 'label' => null];
    }

    /**
     * Get the code the known key makes now.
     */
    public function validProof(Surface $surface): array
    {
        return [TotpType::FIELD => $this->codeAt($this->now())];
    }

    /**
     * Get a code the known key accepts from no step in the window.
     */
    public function rejectedProof(Surface $surface): array
    {
        return [TotpType::FIELD => $this->wrongCode(self::KEY)];
    }

    /**
     * Get the code the ceremony's new key makes now.
     */
    public function validEnrollment(mixed $ceremony): array
    {
        $secret = TotpSecret::fromStored((string) $ceremony);

        return [TotpType::FIELD => $this->codeAt($this->now(), $secret->key)];
    }

    /**
     * Get a code the ceremony's new key accepts from no step in the window.
     */
    public function rejectedEnrollment(mixed $ceremony): array
    {
        $secret = TotpSecret::fromStored((string) $ceremony);

        return [TotpType::FIELD => $this->wrongCode($secret->key)];
    }

    /**
     * Get a code the key accepts from no step in the window.
     */
    protected function wrongCode(string $key): string
    {
        $windowSteps = config()->integer('keystone-totp.window_steps');
        $accepted = array_map(fn (int $offset) => $this->codeAt($this->now() + $offset, $key), range(-$windowSteps, $windowSteps));
        $wrong = 0;

        while (in_array(str_pad((string) $wrong, Totp::DIGITS, '0', STR_PAD_LEFT), $accepted, true)) {
            $wrong++;
        }

        return str_pad((string) $wrong, Totp::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Get the key's code for the step, the arranged key's by default.
     */
    protected function codeAt(int $step, string $key = self::KEY): string
    {
        return (new Totp)->code($key, $step);
    }

    /**
     * Get the current step.
     */
    protected function now(): int
    {
        return (new Totp)->stepAt(Date::now()->getTimestamp());
    }
}
