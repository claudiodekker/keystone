<?php

namespace ClaudioDekker\Keystone\Totp;

use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\EnrolledCredential;
use ClaudioDekker\Keystone\Methods\Initiation;
use ClaudioDekker\Keystone\Methods\InitiateShape;
use ClaudioDekker\Keystone\Methods\Proof;
use ClaudioDekker\Keystone\Methods\StoredCredential;
use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Date;
use LogicException;
use ParagonIE\ConstantTime\Base32;

/**
 * @internal
 */
class TotpType implements CredentialType
{
    /**
     * The input field holding the typed code.
     */
    public const string FIELD = 'code';

    /**
     * How many random bytes a new key holds: 160 bits, as RFC 4226 recommends.
     */
    public const int KEY_BYTES = 20;

    /**
     * Create a new TOTP type instance.
     */
    public function __construct(
        protected Totp $totp = new Totp,
    ) {
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
     * Get the form each surface the type serves shows.
     */
    public function surfaces(): array
    {
        return [
            Surface::CHALLENGE->value => InitiateShape::FORM,
            Surface::ENROLLMENT->value => InitiateShape::FORM,
        ];
    }

    /**
     * Determine if a TOTP proof counts as multiple factors, which it never does.
     */
    public function representsMultipleFactors(): bool
    {
        return false;
    }

    /**
     * Determine if TOTP failures share one count across flows, which they do: a six-digit code is few enough to guess.
     */
    public function sharesFailedAttempts(): bool
    {
        return true;
    }

    /**
     * Get what is wrong with the window, which must be a whole number of steps of at least 0.
     */
    public function configFailures(): array
    {
        $windowSteps = config('keystone-totp.window_steps');

        if (is_int($windowSteps) && $windowSteps >= 0) {
            return [];
        }

        return ['keystone-totp.window_steps must be a whole number of at least 0.'];
    }

    /**
     * Get the rules for the typed code.
     */
    public function rules(Surface $surface): array
    {
        return [self::FIELD => ['required', 'string']];
    }

    /**
     * Make a new key for enrollment, shown as its Base32 form and the otpauth URI an authenticator app reads.
     */
    public function initiate(Surface $surface, string $accountName): Initiation
    {
        if ($surface !== Surface::ENROLLMENT) {
            throw new LogicException("A TOTP code needs no ceremony on {$surface->value}.");
        }

        $secret = new TotpSecret(random_bytes(self::KEY_BYTES), lastStep: null);
        $key = Base32::encodeUpperUnpadded($secret->key);

        return new Initiation(ceremony: $secret->toStored(), page: [
            'key' => $key,
            'uri' => $this->provisioningUri($key, $accountName),
        ]);
    }

    /**
     * Check the typed code against the steps in the window: at the challenge accepting it only from a step after the last one accepted, at enrollment against the new key.
     */
    public function verify(Surface $surface, array $input, array $credentials, mixed $ceremony = null): Proof
    {
        $typed = (string) preg_replace('/\s+/', '', $input[self::FIELD]);
        $now = $this->totp->stepAt(Date::now()->getTimestamp());

        return match ($surface) {
            Surface::CHALLENGE => $this->answer($typed, $now, $credentials),
            Surface::ENROLLMENT => $this->enroll($typed, $now, $ceremony),
            default => throw new LogicException("Verifying a TOTP code on {$surface->value} isn't built yet."),
        };
    }

    /**
     * Check the typed code against the account's TOTP credentials, accepting it only from a step after the last one accepted.
     *
     * @param  list<StoredCredential>  $credentials
     */
    protected function answer(#[\SensitiveParameter] string $typed, int $now, array $credentials): Proof
    {
        foreach ($credentials as $credential) {
            $secret = TotpSecret::fromStored((string) $credential->secret);
            $matched = $this->matchingSteps($secret, $typed, $now);
            $unused = array_filter($matched, fn (int $step) => $secret->lastStep === null || $step > $secret->lastStep);

            if ($unused !== []) {
                return Proof::advanced($credential, $secret->acceptedAt(max($unused))->toStored());
            }

            if ($matched !== []) {
                return Proof::rejected('totp.replayed', $credential);
            }
        }

        return Proof::rejected('totp.mismatch', $credentials[0] ?? null);
    }

    /**
     * Check the typed code against the key the enrollment made, storing the key with the code's step as the last one accepted.
     */
    protected function enroll(#[\SensitiveParameter] string $typed, int $now, #[\SensitiveParameter] mixed $ceremony): Proof
    {
        if (! is_string($ceremony)) {
            throw new LogicException('A TOTP enrollment needs the key its ceremony made.');
        }

        $secret = TotpSecret::fromStored($ceremony);
        $matched = $this->matchingSteps($secret, $typed, $now);

        if ($matched === []) {
            return Proof::rejected('totp.mismatch');
        }

        return Proof::enrolled(new EnrolledCredential(identifier: null, secret: $secret->acceptedAt(max($matched))->toStored()));
    }

    /**
     * Get the otpauth URI that adds the key to an authenticator app, labelled with the app's name and the account's.
     */
    protected function provisioningUri(#[\SensitiveParameter] string $key, string $accountName): string
    {
        $issuer = (string) config('app.name');
        $label = rawurlencode($issuer).':'.rawurlencode($accountName);

        $query = Arr::query([
            'secret' => $key,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => Totp::DIGITS,
            'period' => Totp::STEP_SECONDS,
        ]);

        return "otpauth://totp/{$label}?{$query}";
    }

    /**
     * Get the steps in the window around now whose code is the typed one, checking every step in constant time.
     *
     * @return list<int>
     */
    protected function matchingSteps(TotpSecret $secret, string $typed, int $now): array
    {
        $windowSteps = config()->integer('keystone-totp.window_steps');
        $matched = [];

        foreach (range($now - $windowSteps, $now + $windowSteps) as $step) {
            if (hash_equals($this->totp->code($secret->key, $step), $typed)) {
                $matched[] = $step;
            }
        }

        return $matched;
    }
}
