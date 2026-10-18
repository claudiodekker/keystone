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
     * The entry lines of a JSON object laid out one per line, with the indent they share.
     */
    protected const string OBJECT_ENTRIES = '(?<entries>(?:\n(?<indent>[ \t]+)"[^"\n]+": "[^"\n]*",?)+)\n';

    /**
     * Get the file the edit changes, relative to the app's base path, or null when it can't be found.
     */
    abstract public function path(): ?string;

    /**
     * Get the file's contents with the edit made, or null when the file no longer has the shape the edit recognises.
     */
    abstract public function apply(string $contents): ?string;

    /**
     * Get the change to make by hand.
     */
    abstract public function instructions(): string;

    /**
     * Get the contents with the class imported in its sorted place, or null when the file imports nothing.
     */
    protected function import(string $contents, string $class): ?string
    {
        $import = "use {$class};";

        if (str_contains($contents, $import)) {
            return $contents;
        }

        if (preg_match_all('/^use [^;]+;$/m', $contents, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return null;
        }

        $end = 0;

        foreach ($matches[0] as [$line, $offset]) {
            if (strcmp($line, $import) > 0) {
                return substr_replace($contents, $import."\n", $offset, 0);
            }

            $end = $offset + strlen($line);
        }

        return substr_replace($contents, "\n".$import, $end, 0);
    }

    /**
     * Get the entry line of a key and its string value, as npm and Composer write it.
     */
    protected function entry(string $key, string $value): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

        return json_encode($key, $flags).': '.json_encode($value, $flags);
    }
}
