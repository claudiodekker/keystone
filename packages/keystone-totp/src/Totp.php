<?php

namespace ClaudioDekker\Keystone\Totp;

use SensitiveParameter;

/**
 * @internal
 */
class Totp
{
    /**
     * The seconds each code is valid for, as RFC 6238 and every authenticator app default to.
     */
    public const int STEP_SECONDS = 30;

    /**
     * The digits of each code.
     */
    protected const int DIGITS = 6;

    /**
     * Get the step the Unix second falls in.
     */
    public static function stepAt(int $timestamp): int
    {
        return intdiv($timestamp, static::STEP_SECONDS);
    }

    /**
     * Get the code of the raw key for the step, as RFC 6238 makes it with HMAC-SHA1.
     */
    public static function code(#[SensitiveParameter] string $key, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), $key, true);
        $offset = ord($hash[19]) & 0xF;

        $truncated = (ord($hash[$offset]) & 0x7F) << 24
            | ord($hash[$offset + 1]) << 16
            | ord($hash[$offset + 2]) << 8
            | ord($hash[$offset + 3]);

        return str_pad((string) ($truncated % 10 ** static::DIGITS), static::DIGITS, '0', STR_PAD_LEFT);
    }
}
