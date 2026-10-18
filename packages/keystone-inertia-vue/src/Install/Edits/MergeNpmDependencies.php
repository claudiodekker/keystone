<?php

namespace ClaudioDekker\Keystone\InertiaVue\Install\Edits;

use ClaudioDekker\Keystone\InertiaVue\Install\Edit;

/**
 * Add the given npm packages to the app's devDependencies, keeping any version the app pins.
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

        return $this->insert($contents, $missing, array_keys($manifest['devDependencies'] ?? []));
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
     * Get the contents with each package added before the first devDependency that sorts after it, or last.
     *
     * @param  array<string, string>  $packages
     * @param  list<int|string>  $existing  The app's devDependencies, in the order the file has them.
     */
    protected function insert(string $contents, array $packages, array $existing): ?string
    {
        $pattern = '/"devDependencies": \{'.static::OBJECT_ENTRIES.'/';

        if (preg_match($pattern, $contents, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        [$entries, $offset] = $match['entries'];
        $indent = $match['indent'][0];

        ksort($packages, SORT_STRING);

        $lines = explode(",\n", ltrim($entries, "\n"));

        if (count($lines) !== count($existing)) {
            return null;
        }

        $lines = array_combine($existing, $lines);
        $merged = [];

        foreach ($lines as $key => $line) {
            foreach ($packages as $package => $version) {
                if ($package < $key) {
                    $merged[] = $indent.$this->entry($package, $version);
                    unset($packages[$package]);
                }
            }

            $merged[] = $line;
        }

        foreach ($packages as $package => $version) {
            $merged[] = $indent.$this->entry($package, $version);
        }

        return substr_replace($contents, "\n".implode(",\n", $merged), $offset, strlen($entries));
    }
}
