<?php

namespace ClaudioDekker\Keystone\Exceptions;

use ClaudioDekker\Keystone\Methods\Surface;
use LogicException;

/**
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
