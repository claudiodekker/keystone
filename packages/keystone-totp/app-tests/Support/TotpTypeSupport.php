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

    public function type(): string
    {
        return 'totp';
    }

    public function arrange(Surface $surface): array
    {
        $secret = new TotpSecret(self::KEY, lastStep: null);

        return ['identifier' => null, 'secret' => $secret->toStored(), 'label' => null];
    }

    public function validProof(Surface $surface): array
    {
        return [TotpType::FIELD => $this->codeAt($this->now())];
    }

    public function rejectedProof(Surface $surface): array
    {
        $windowSteps = config()->integer('keystone-totp.window_steps');
        $accepted = array_map(fn (int $offset) => $this->codeAt($this->now() + $offset), range(-$windowSteps, $windowSteps));
        $wrong = 0;

        while (in_array(str_pad((string) $wrong, Totp::DIGITS, '0', STR_PAD_LEFT), $accepted, true)) {
            $wrong++;
        }

        return [TotpType::FIELD => str_pad((string) $wrong, Totp::DIGITS, '0', STR_PAD_LEFT)];
    }

    /**
     * Get the arranged key's code for the step.
     */
    protected function codeAt(int $step): string
    {
        return (new Totp)->code(self::KEY, $step);
    }

    /**
     * Get the current step.
     */
    protected function now(): int
    {
        return (new Totp)->stepAt(Date::now()->getTimestamp());
    }
}
