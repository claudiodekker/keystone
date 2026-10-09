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
        $subkey = hash_hmac('sha256', $purpose, app('encrypter')->getKey(), binary: true);

        return hash_hmac('sha256', $message, $subkey);
    }
}
