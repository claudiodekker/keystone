<?php

namespace ClaudioDekker\Keystone;

/**
 * @internal
 */
class Hmac
{
    /**
     * Hash the message under the purpose's own subkey of the app key.
     */
    public static function make(string $purpose, string $message): string
    {
        return hash_hmac('sha256', $message, static::subkey($purpose));
    }

    /**
     * Get the purpose's own 32-byte subkey of the current app key, which no previous key ever derives.
     */
    public static function subkey(string $purpose): string
    {
        return hash_hmac('sha256', $purpose, app('encrypter')->getKey(), binary: true);
    }
}
