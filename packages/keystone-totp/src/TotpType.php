<?php

namespace ClaudioDekker\Keystone\Totp;

use ClaudioDekker\Keystone\Methods\ChecksConfiguration;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\InitiateShape;
use ClaudioDekker\Keystone\Methods\OneTimeCode;
use ClaudioDekker\Keystone\Methods\Proof;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Methods\Verification;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Date;
use SensitiveParameter;

/**
 * @internal
 */
class TotpType implements ChecksConfiguration, CredentialType, OneTimeCode
{
    /**
     * The key a code is checked against when the subject holds no usable secret, so the check costs what a real one does.
     */
    protected const string DECOY_KEY = 'keystone-totp-decoy-key';

    /**
     * Create the type.
     */
    public function __construct(protected Repository $config)
    {
        //
    }

    /**
     * Get the type's name.
     */
    public function name(): string
    {
        return 'totp';
    }

    /**
     * Get the form shape on the challenge, and nothing on every other surface.
     */
    public function initiateShape(Surface $surface): ?InitiateShape
    {
        if ($surface !== Surface::CHALLENGE) {
            return null;
        }

        return InitiateShape::FORM;
    }

    /**
     * Determine if a proof proves two factors on its own, which a code never does.
     */
    public function isMultiFactor(): bool
    {
        return false;
    }

    /**
     * Get the rules for the code.
     *
     * @return array<string, mixed>
     */
    public function rules(Surface $surface): array
    {
        return [
            'code' => ['required', 'string', 'digits:6'],
        ];
    }

    /**
     * Prove the credential whose key makes the code within the window, advancing it to the code's step, unless that step was accepted already.
     */
    public function verify(Verification $verification): Proof
    {
        $code = (string) $verification->input['code'];
        $now = Totp::stepAt(Date::now()->getTimestamp());
        $usable = 0;

        foreach ($verification->credentials as $credential) {
            $secret = TotpSecret::fromStored($credential->secret);

            if ($secret === null) {
                continue;
            }

            $usable++;
            $step = $this->matchingStep($secret->key, $code, $now);

            if ($step === null) {
                continue;
            }

            if ($secret->hasAccepted($step)) {
                return Proof::rejected('totp.replayed');
            }

            return Proof::advanced($credential, $secret->accepting($step)->toStored());
        }

        if ($usable === 0) {
            $this->matchingStep(static::DECOY_KEY, $code, $now);
        }

        return Proof::rejected();
    }

    /**
     * Get the failure of each problem with the type's config.
     *
     * @return list<string>
     */
    public function configurationFailures(): array
    {
        $window = $this->config->get('keystone.totp.window_steps');

        if (is_int($window) && $window >= 0) {
            return [];
        }

        return ['keystone.totp.window_steps must be a whole number of steps of at least 0.'];
    }

    /**
     * Get the step within the window whose code the key makes, comparing every step so the time taken doesn't tell which matched.
     */
    protected function matchingStep(#[SensitiveParameter] string $key, #[SensitiveParameter] string $code, int $now): ?int
    {
        $window = $this->config->integer('keystone.totp.window_steps');
        $matched = null;

        foreach (range($now - $window, $now + $window) as $step) {
            if (hash_equals(Totp::code($key, $step), $code)) {
                $matched ??= $step;
            }
        }

        return $matched;
    }
}
