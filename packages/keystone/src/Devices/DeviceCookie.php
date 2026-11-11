<?php

namespace ClaudioDekker\Keystone\Devices;

use SensitiveParameter;

/**
 * @internal
 *
 * @phpstan-consistent-constructor
 */
readonly class DeviceCookie
{
    /**
     * The name of the cookie in the browser.
     */
    public const string NAME = '__Host-keystone_device';

    /**
     * The random bytes of the part that names the device.
     *
     * @var positive-int
     */
    protected const int DEVICE_BYTES = 16;

    /**
     * The random bytes of the part every sign-in replaces.
     *
     * @var positive-int
     */
    protected const int KEY_BYTES = 32;

    /**
     * Create the cookie from the part that names the device and the part every sign-in replaces, each in hex.
     */
    public function __construct(
        public string $device,
        #[SensitiveParameter] public string $key,
    ) {
        //
    }

    /**
     * Make the cookie of a device no sign-in has named yet.
     */
    public static function generate(): static
    {
        return new static(bin2hex(random_bytes(static::DEVICE_BYTES)), static::generateKey());
    }

    /**
     * Get the cookie a browser sent, or null when it sent none or one Keystone could never have handed out.
     */
    public static function fromValue(#[SensitiveParameter] ?string $value): ?static
    {
        $pattern = sprintf('/^([0-9a-f]{%d})\.([0-9a-f]{%d})\z/', static::DEVICE_BYTES * 2, static::KEY_BYTES * 2);

        if ($value === null || preg_match($pattern, $value, $parts) !== 1) {
            return null;
        }

        return new static($parts[1], $parts[2]);
    }

    /**
     * Get the cookie of the same device with a fresh part in place of the one a sign-in replaces.
     */
    public function rekeyed(): static
    {
        return new static($this->device, static::generateKey());
    }

    /**
     * Get the value the browser holds.
     */
    public function value(): string
    {
        return $this->device.'.'.$this->key;
    }

    /**
     * Get the digest of the whole value, which only the latest sign-in's cookie matches.
     */
    public function digest(): string
    {
        return hash('sha256', $this->value());
    }

    /**
     * Get the digest of the part that names the device, which every cookie of the device matches.
     */
    public function deviceDigest(): string
    {
        return hash('sha256', $this->device);
    }

    /**
     * Generate the random part a sign-in replaces.
     */
    protected static function generateKey(): string
    {
        return bin2hex(random_bytes(static::KEY_BYTES));
    }
}
