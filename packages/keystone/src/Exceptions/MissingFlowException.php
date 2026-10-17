<?php

namespace ClaudioDekker\Keystone\Exceptions;

use ClaudioDekker\Keystone\Methods\Surface;
use LogicException;

/**
 * Refuse to count a failed attempt on a surface that is in no flow.
 *
 * @internal
 *
 * @phpstan-consistent-constructor
 */
class MissingFlowException extends LogicException
{
    /**
     * Create the exception for the surface.
     */
    public static function forSurface(Surface $surface): static
    {
        return new static("No flow counts failed attempts on the [{$surface->value}] surface.");
    }
}
