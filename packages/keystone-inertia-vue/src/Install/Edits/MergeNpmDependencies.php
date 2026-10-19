<?php

namespace ClaudioDekker\Keystone\InertiaVue\Install\Edits;

use ClaudioDekker\Keystone\InertiaVue\Install\Edit;

/**
 * Add the given npm packages to the app's devDependencies, at the given versions.
 *
 * @internal
 */
class MergeNpmDependencies extends Edit
{
    /**
     * Create the edit of the given packages.
     *
     * @param  array<string, string>  $packages  The version by package name.
     */
    public function __construct(protected array $packages)
    {
        //
    }

    /**
     * Get the file the edit changes, relative to the app's base path.
     */
    public function path(): string
    {
        return 'package.json';
    }

    /**
     * Get the file's contents with every package set in devDependencies, sorted by name.
     */
    public function apply(string $contents): ?string
    {
        $manifest = json_decode($contents, true);

        if (! is_array($manifest)) {
            return null;
        }

        $manifest['devDependencies'] = $this->packages + ($manifest['devDependencies'] ?? []);

        ksort($manifest['devDependencies']);

        return json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    }

    /**
     * Get the change to make by hand.
     */
    public function instructions(): string
    {
        $packages = json_encode($this->packages, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return "Add these packages to devDependencies in package.json:\n\n".$packages;
    }
}
