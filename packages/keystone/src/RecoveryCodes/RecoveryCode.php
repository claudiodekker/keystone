<?php

namespace ClaudioDekker\Keystone\RecoveryCodes;

use SensitiveParameter;

/**
 * @internal
 *
 * @phpstan-consistent-constructor
 */
readonly class RecoveryCode
{
    /**
     * The characters a code is drawn from.
     */
    protected const string ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    /**
     * The characters in a code.
     */
    protected const int LENGTH = 26;

    /**
     * The characters in each dash-separated block a code is shown in.
     */
    protected const int BLOCK_LENGTH = 5;

    /**
     * The codes in a set.
     */
    protected const int SET_SIZE = 8;

    /**
     * Create the code from its normalized characters.
     */
    protected function __construct(#[SensitiveParameter] public string $value)
    {
        //
    }

    /**
     * Generate a set of codes, each drawn on its own.
     *
     * @return list<static>
     */
    public static function generateSet(): array
    {
        return array_map(fn () => static::generate(), range(1, static::SET_SIZE));
    }

    /**
     * Generate a code, drawing each character uniformly from the alphabet.
     */
    public static function generate(): static
    {
        $characters = array_map(
            fn () => static::ALPHABET[random_int(0, strlen(static::ALPHABET) - 1)],
            range(1, static::LENGTH),
        );

        return new static(implode('', $characters));
    }

    /**
     * Get the code a user typed, without its dashes and whitespace and in upper case.
     */
    public static function fromInput(#[SensitiveParameter] string $input): static
    {
        $characters = preg_replace('/[\s-]+/u', '', $input) ?? '';

        return new static(strtoupper($characters));
    }

    /**
     * Get the digest the code is stored under.
     */
    public function digest(): string
    {
        return hash('sha256', $this->value);
    }

    /**
     * Get the code as it is shown, in dash-separated blocks.
     */
    public function format(): string
    {
        return implode('-', str_split($this->value, static::BLOCK_LENGTH));
    }
}
