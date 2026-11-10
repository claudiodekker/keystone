<?php

namespace ClaudioDekker\Keystone\Exceptions;

use RuntimeException;

/**
 * @api
 *
 * @phpstan-consistent-constructor
 */
class AccountChangeRefusedException extends RuntimeException
{
    /**
     * Create the exception for suspending the account with the id, which is already suspended.
     */
    public static function alreadySuspended(int|string $id): static
    {
        return new static("The account [{$id}] is already suspended.");
    }

    /**
     * Create the exception for unsuspending the account with the id, which isn't suspended.
     */
    public static function notSuspended(int|string $id): static
    {
        return new static("The account [{$id}] isn't suspended.");
    }

    /**
     * Create the exception for a rehash over a secret another write changed since it was read.
     */
    public static function secretChanged(): static
    {
        return new static('The credential secret changed since it was read, so its rehash was refused.');
    }

    /**
     * Create the exception for a change written by a pending sign-in whose account left its credential epoch or was barred.
     */
    public static function writerEnded(): static
    {
        return new static('The pending sign-in writing the change ended, so the change was refused.');
    }
}
