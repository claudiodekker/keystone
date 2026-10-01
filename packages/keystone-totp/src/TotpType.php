<?php

namespace ClaudioDekker\Keystone\Totp;

use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\InitiateShape;
use ClaudioDekker\Keystone\Methods\Proof;
use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Support\Facades\Date;
use LogicException;

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
     * Check the typed code against the steps in the window, accepting it only from a step after the last one accepted.
     */
    public function verify(Surface $surface, array $input, array $credentials): Proof
    {
        if ($surface !== Surface::CHALLENGE) {
            throw new LogicException("Verifying a TOTP code on {$surface->value} isn't built yet.");
        }

        $typed = (string) preg_replace('/\s+/', '', $input[self::FIELD]);
        $now = $this->totp->stepAt(Date::now()->getTimestamp());

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
