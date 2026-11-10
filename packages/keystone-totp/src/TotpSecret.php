<?php

namespace ClaudioDekker\Keystone\Totp;

use JsonException;
use ParagonIE\ConstantTime\Base32;
use RangeException;
use SensitiveParameter;

/**
 * @internal
 *
 * @phpstan-consistent-constructor
 */
readonly class TotpSecret
{
    /**
     * Create the secret.
     *
     * @param  string  $key  the raw key
     * @param  int|null  $lastStep  the step of the last code accepted, null until one is
     */
    public function __construct(
        #[SensitiveParameter] public string $key,
        public ?int $lastStep,
    ) {
        //
    }

    /**
     * Read the secret a credential stores, or null when it holds none.
     */
    public static function fromStored(#[SensitiveParameter] ?string $stored): ?static
    {
        try {
            $value = json_decode((string) $stored, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($value)) {
            return null;
        }

        $lastStep = $value['last_step'] ?? null;

        if (! is_int($lastStep) && $lastStep !== null) {
            return null;
        }

        $secret = is_string($value['key'] ?? null) ? static::fromKey($value['key']) : null;

        if ($secret === null) {
            return null;
        }

        return new static($secret->key, $lastStep);
    }

    /**
     * Read a key from its Base32 form with no code accepted yet, or null when it holds none.
     */
    public static function fromKey(#[SensitiveParameter] string $base32): ?static
    {
        try {
            $key = Base32::decodeUpper($base32);
        } catch (RangeException) {
            return null;
        }

        return $key === '' ? null : new static($key, lastStep: null);
    }

    /**
     * Get the secret as a credential stores it: the key in Base32 and the last accepted step.
     */
    public function toStored(): string
    {
        return json_encode([
            'key' => Base32::encodeUpperUnpadded($this->key),
            'last_step' => $this->lastStep,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Get the secret with the step as the last accepted.
     */
    public function accepting(int $step): static
    {
        return new static($this->key, $step);
    }

    /**
     * Determine if a code of the step was accepted already: the last accepted step or an earlier one.
     */
    public function hasAccepted(int $step): bool
    {
        return $this->lastStep !== null && $step <= $this->lastStep;
    }
}
