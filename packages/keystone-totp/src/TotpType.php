<?php

namespace ClaudioDekker\Keystone\Totp;

use ClaudioDekker\Keystone\Methods\ChecksConfiguration;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\InitiateShape;
use ClaudioDekker\Keystone\Methods\OneTimeCode;
use ClaudioDekker\Keystone\Methods\Proof;
use ClaudioDekker\Keystone\Methods\StartsCeremony;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Methods\Verification;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Uri;
use ParagonIE\ConstantTime\Base32;
use SensitiveParameter;

/**
 * @internal
 */
class TotpType implements ChecksConfiguration, CredentialType, OneTimeCode, StartsCeremony
{
    /**
     * The key a code is checked against when the subject holds no usable secret, so the check costs what a real one does.
     */
    protected const string DECOY_KEY = 'keystone-totp-decoy-key';

    /**
     * The bytes of a new key, 160 bits as RFC 4226 recommends.
     *
     * @var positive-int
     */
    protected const int KEY_BYTES = 20;

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
     * Get the form shape on the challenge and on enrollment, and nothing on every other surface.
     */
    public function initiateShape(Surface $surface): ?InitiateShape
    {
        return match ($surface) {
            Surface::CHALLENGE, Surface::ENROLLMENT => InitiateShape::FORM,
            default => null,
        };
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
     *
     * On enrollment, enroll the key being set up once it makes the code, with the code's step accepted.
     */
    public function verify(Verification $verification): Proof
    {
        if ($verification->surface === Surface::ENROLLMENT) {
            return $this->enroll($verification);
        }

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
     * Start setting up a new key, kept in Base32 until its first code is right.
     *
     * @return array{key: string}
     */
    public function start(Surface $surface): array
    {
        return ['key' => Base32::encodeUpperUnpadded(random_bytes(static::KEY_BYTES))];
    }

    /**
     * Show the key being set up in Base32 for typing in, and as the otpauth:// URI an authenticator app scans, labelled with the app's name and the account.
     *
     * @param  array<string, mixed>  $ceremony
     * @return array{key: string, uri: string}
     */
    public function page(Surface $surface, array $ceremony, string $account): array
    {
        $key = (string) ($ceremony['key'] ?? '');
        $issuer = (string) $this->config->get('app.name');

        $uri = Uri::of('otpauth://totp/'.rawurlencode($issuer.':'.$account))->withQuery([
            'secret' => $key,
            'issuer' => $issuer,
        ]);

        return [
            'key' => $key,
            'uri' => (string) $uri,
        ];
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
     * Enroll the key the ceremony set up when it makes the code within the window, with that step accepted.
     */
    protected function enroll(Verification $verification): Proof
    {
        $code = (string) $verification->input['code'];
        $now = Totp::stepAt(Date::now()->getTimestamp());
        $key = $verification->ceremony['key'] ?? null;
        $secret = is_string($key) ? TotpSecret::fromKey($key) : null;

        if ($secret === null) {
            $this->matchingStep(static::DECOY_KEY, $code, $now);

            return Proof::rejected();
        }

        $step = $this->matchingStep($secret->key, $code, $now);

        if ($step === null) {
            return Proof::rejected();
        }

        return Proof::enrolled($secret->accepting($step)->toStored());
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
