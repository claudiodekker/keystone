<?php

namespace ClaudioDekker\Keystone\Password;

/**
 * @internal
 */
enum HashAlgorithm: string
{
    case BCRYPT = 'bcrypt';
    case ARGON2I = 'argon2i';
    case ARGON2ID = 'argon2id';

    /**
     * Get the algorithm the hash was made with, if it is one Keystone verifies.
     */
    public static function of(string $hash): ?self
    {
        return self::tryFrom(password_get_info($hash)['algoName']);
    }

    /**
     * Get the name of the Laravel hashing driver that verifies the algorithm's hashes.
     */
    public function driver(): string
    {
        return match ($this) {
            self::BCRYPT => 'bcrypt',
            self::ARGON2I => 'argon',
            self::ARGON2ID => 'argon2id',
        };
    }
}
