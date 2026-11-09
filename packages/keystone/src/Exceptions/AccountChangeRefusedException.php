<?php

namespace ClaudioDekker\Keystone\Exceptions;

use RuntimeException;

/**
 * @internal
 *
 * @phpstan-consistent-constructor
 */
class AccountChangeRefusedException extends RuntimeException
{
    /**
     * Create the exception for a rehash over a secret another write changed since it was read.
     */
    public static function secretChanged(): static
    {
        return new static('The credential secret changed since it was read, so its rehash was refused.');
    }
}
