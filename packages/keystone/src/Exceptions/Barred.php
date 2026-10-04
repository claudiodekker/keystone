<?php

namespace ClaudioDekker\Keystone\Exceptions;

use LogicException;

/**
 * @internal
 *
 * @phpstan-consistent-constructor
 */
class Barred extends LogicException
{
    /**
     * Create an exception for an account that no longer exists.
     */
    public static function missing(): static
    {
        return new static('The account being signed in no longer exists.');
    }

    /**
     * Create an exception for an account that is disabled or suspended.
     */
    public static function inactive(): static
    {
        return new static('The account being signed in is disabled or suspended.');
    }
}
