<?php

namespace ClaudioDekker\Keystone\InertiaVue\Install;

/**
 * @internal
 */
abstract class Edit
{
    /**
     * Get the file the edit changes, relative to the app's base path, or null when it can't be found.
     */
    abstract public function path(): ?string;

    /**
     * Get the file's contents with the edit made, or null when the file no longer has the shape the edit recognises.
     *
     * Applying it to its own result returns that result unchanged.
     */
    abstract public function apply(string $contents): ?string;

    /**
     * Get the change to make by hand.
     */
    abstract public function instructions(): string;
}
