<?php

namespace ClaudioDekker\Keystone\InertiaVue\Install\Edits;

use ClaudioDekker\Keystone\InertiaVue\Install\Edit;

/**
 * Add the npm packages the pages need to the app's devDependencies, keeping the version the app pins.
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
     * Get the file's contents with every package the app lacks added.
     */
    public function apply(string $contents): ?string
    {
        $manifest = json_decode($contents, true);

        if (! is_array($manifest)) {
            return null;
        }

        $pinned = ($manifest['dependencies'] ?? []) + ($manifest['devDependencies'] ?? []);
        $missing = array_diff_key($this->packages, $pinned);

        if ($missing === []) {
            return $contents;
        }

        return $this->insert($contents, $missing);
    }

    /**
     * Get the change to make by hand.
     */
    public function instructions(): string
    {
        $packages = json_encode($this->packages, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return "Add these packages to devDependencies in package.json, keeping any version you pin:\n\n".$packages;
    }

    /**
     * Get the contents with the packages added to devDependencies in sorted order.
     *
     * @param  array<string, string>  $packages
     */
    protected function insert(string $contents, array $packages): ?string
    {
        $pattern = '/"devDependencies": \{(?<entries>(?:\n(?<indent>[ \t]+)"[^"\n]+": "[^"\n]*",?)+)\n/';

        if (preg_match($pattern, $contents, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        [$entries, $offset] = $match['entries'];
        $indent = $match['indent'][0];

        $lines = collect(explode(",\n", trim($entries, "\n")))
            ->keyBy(fn (string $line): string => (string) json_decode(explode(':', trim($line), 2)[0]))
            ->merge(collect($packages)->map(fn (string $version, string $package) => $indent.json_encode($package).': '.json_encode($version)))
            ->sortKeys();

        return substr_replace($contents, "\n".$lines->implode(",\n"), $offset, strlen($entries));
    }
}
