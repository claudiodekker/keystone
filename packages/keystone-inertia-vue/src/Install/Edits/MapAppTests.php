<?php

namespace ClaudioDekker\Keystone\InertiaVue\Install\Edits;

use ClaudioDekker\Keystone\InertiaVue\Install\Edit;

/**
 * Map every installed Keystone package's AppTests namespace in the app's autoload-dev, never in autoload.
 *
 * @internal
 */
class MapAppTests extends Edit
{
    /**
     * Create the edit of the given mappings.
     *
     * @param  array<string, string>  $namespaces  The path under vendor/ by namespace.
     */
    public function __construct(protected array $namespaces)
    {
        //
    }

    /**
     * Get the file the edit changes, relative to the app's base path.
     */
    public function path(): string
    {
        return 'composer.json';
    }

    /**
     * Get the file's contents with every namespace mapped.
     */
    public function apply(string $contents): ?string
    {
        $manifest = json_decode($contents, true);

        if (! is_array($manifest)) {
            return null;
        }

        $unmapped = [];

        foreach ($this->namespaces as $namespace => $path) {
            $mapped = $manifest['autoload-dev']['psr-4'][$namespace] ?? null;

            if ($mapped !== null && $mapped !== $path) {
                return null;
            }

            if ($mapped === null) {
                $unmapped[$namespace] = $path;
            }
        }

        if ($unmapped === []) {
            return $contents;
        }

        return $this->insert($contents, $unmapped);
    }

    /**
     * Get the change to make by hand.
     */
    public function instructions(): string
    {
        $mappings = json_encode($this->namespaces, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return "Add these mappings to autoload-dev.psr-4 in composer.json:\n\n".$mappings;
    }

    /**
     * Get the contents with the mappings added to autoload-dev.psr-4.
     *
     * @param  array<string, string>  $mappings
     */
    protected function insert(string $contents, array $mappings): ?string
    {
        $pattern = '/"autoload-dev": \{\s*"psr-4": \{(?<entries>(?:\n(?<indent>[ \t]+)"[^"\n]+": "[^"\n]*",?)+)\n/';

        if (preg_match($pattern, $contents, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        [$entries, $offset] = $match['entries'];
        $indent = $match['indent'][0];

        $added = collect($mappings)
            ->map(fn (string $path, string $namespace) => "\n{$indent}".json_encode($namespace, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).': '.json_encode($path, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))
            ->implode(',');

        return substr_replace($contents, $entries.','.$added, $offset, strlen($entries));
    }
}
