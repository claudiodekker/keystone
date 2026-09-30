<?php

namespace ClaudioDekker\Keystone\Totp;

/**
 * @internal
 */
class Totp
{
    /**
     * The digits of a code.
     */
    public const int DIGITS = 6;

    /**
     * How long each code lasts.
     */
    public const int STEP_SECONDS = 30;

    /**
     * Get the step the time falls in.
     */
    public function stepAt(int $timestamp): int
    {
        return intdiv($timestamp, self::STEP_SECONDS);
    }

    /**
     * Get the RFC 6238 code of the key for the step, using HMAC-SHA1.
     */
    public function code(#[\SensitiveParameter] string $key, int $step): string
    {
        $hmac = hash_hmac('sha1', pack('J', $step), $key, binary: true);

        // RFC 4226's dynamic truncation: 31 bits from the offset the last nibble names.
        $offset = ord($hmac[19]) & 0x0F;
        $truncated = (ord($hmac[$offset]) & 0x7F) << 24
            | ord($hmac[$offset + 1]) << 16
            | ord($hmac[$offset + 2]) << 8
            | ord($hmac[$offset + 3]);

        return str_pad((string) ($truncated % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }
}
