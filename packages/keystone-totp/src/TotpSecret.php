<?php

namespace ClaudioDekker\Keystone\Totp;

use InvalidArgumentException;
use JsonException;
use ParagonIE\ConstantTime\Base32;

/**
 * @internal
 *
 * @phpstan-consistent-constructor
 */
readonly class TotpSecret
{
    /**
     * Create a new TOTP secret instance.
     */
    public function __construct(
        #[\SensitiveParameter] public string $key,
        public ?int $lastStep,
    ) {
        //
    }

    /**
     * Read a secret as core stores it: the Base32 key and the last step a code was accepted from.
     *
     * @throws InvalidArgumentException
     * @throws JsonException
     */
    public static function fromStored(#[\SensitiveParameter] string $stored): static
    {
        $secret = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
        $key = is_array($secret) ? $secret['key'] ?? null : null;
        $lastStep = is_array($secret) ? $secret['last_step'] ?? null : null;

        if (! is_string($key) || ! (is_int($lastStep) || $lastStep === null)) {
            throw new InvalidArgumentException('The stored TOTP secret is malformed.');
        }

        return new static(Base32::decodeUpper($key), $lastStep);
    }

    /**
     * Get the secret with the step a code was just accepted from as its last.
     */
    public function acceptedAt(int $step): static
    {
        return new static($this->key, $step);
    }

    /**
     * Get the secret as core stores it.
     */
    public function toStored(): string
    {
        return json_encode([
            'key' => Base32::encodeUpperUnpadded($this->key),
            'last_step' => $this->lastStep,
        ], JSON_THROW_ON_ERROR);
    }
}
