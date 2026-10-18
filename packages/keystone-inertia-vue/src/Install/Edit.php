<?php

namespace ClaudioDekker\Keystone\InertiaVue\Install;

/**
 * Change one of the app's files the way a fresh Laravel skeleton lays it out.
 *
 * apply() converges: applying it to its own result returns that result unchanged.
 *
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
     */
    abstract public function apply(string $contents): ?string;

    /**
     * Get the change to make by hand, printed when the file drifted too far to edit.
     */
    abstract public function instructions(): string;
}
