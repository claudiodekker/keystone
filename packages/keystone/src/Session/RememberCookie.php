<?php

namespace ClaudioDekker\Keystone\Session;

use SensitiveParameter;

/**
 * @internal
 *
 * @phpstan-consistent-constructor
 */
readonly class RememberCookie
{
    /**
     * The name of the cookie in the browser.
     */
    public const string NAME = '__Host-keystone_remember';

    /**
     * The random bytes of the value.
     *
     * @var positive-int
     */
    protected const int BYTES = 32;

    /**
     * Create the cookie from its value in hex.
     */
    public function __construct(
        #[SensitiveParameter] public string $value,
    ) {
        //
    }

    /**
     * Make a cookie of a fresh random value.
     */
    public static function generate(): static
    {
        return new static(bin2hex(random_bytes(static::BYTES)));
    }

    /**
     * Get the cookie a browser sent, or null when it sent none or one Keystone could never have handed out.
     */
    public static function fromValue(#[SensitiveParameter] ?string $value): ?static
    {
        $pattern = sprintf('/^[0-9a-f]{%d}\z/', static::BYTES * 2);

        if ($value === null || preg_match($pattern, $value) !== 1) {
            return null;
        }

        return new static($value);
    }

    /**
     * Get the value the browser holds.
     */
    public function value(): string
    {
        return $this->value;
    }

    /**
     * Get the digest of the value, which is all the server keeps.
     */
    public function digest(): string
    {
        return hash('sha256', $this->value);
    }
}
