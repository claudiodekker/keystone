<?php

namespace ClaudioDekker\Keystone\Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use SimpleXMLElement;
use Symfony\Component\Yaml\Yaml;

/**
 * Read the files that wire the packages into one repository, so a test can check them against packages/*.
 */
class Monorepo
{
    /**
     * Get the directory name of every package, core included.
     *
     * @return list<string>
     */
    public static function packages(): array
    {
        return collect((new Filesystem)->glob(static::path('packages/*/composer.json')))
            ->map(fn (string $path) => basename(dirname($path)))
            ->values()
            ->all();
    }

    /**
     * Get the directory name of every package but core.
     *
     * @return list<string>
     */
    public static function methodPackages(): array
    {
        return collect(static::packages())
            ->reject(fn (string $package) => $package === 'keystone')
            ->values()
            ->all();
    }

    /**
     * Get the decoded composer.json of a package, or of the repository root when no package is named.
     *
     * @return array<string, mixed>
     */
    public static function manifest(?string $package = null): array
    {
        return (new Filesystem)->json(static::path($package === null ? 'composer.json' : "packages/{$package}/composer.json"));
    }

    /**
     * Get the testsuites of phpunit.xml by name.
     *
     * @return Collection<string, SimpleXMLElement>
     */
    public static function suites(): Collection
    {
        $xml = simplexml_load_file(static::path('phpunit.xml'));

        return collect(iterator_to_array($xml->testsuites->testsuite, false))
            ->mapWithKeys(fn (SimpleXMLElement $suite) => [(string) $suite['name'] => $suite]);
    }

    /**
     * Get the directories a testsuite reads, as paths from the repository root.
     *
     * @return list<string>
     */
    public static function directories(SimpleXMLElement $suite): array
    {
        return collect(iterator_to_array($suite->directory, false))
            ->map(fn (SimpleXMLElement $directory) => (string) $directory)
            ->values()
            ->all();
    }

    /**
     * Get the directories a testsuite leaves out, as paths from the repository root.
     *
     * @return list<string>
     */
    public static function excluded(SimpleXMLElement $suite): array
    {
        return collect(iterator_to_array($suite->exclude, false))
            ->map(fn (SimpleXMLElement $directory) => (string) $directory)
            ->values()
            ->all();
    }

    /**
     * Get the commands a CI job runs, in order.
     *
     * @return list<string>
     */
    public static function commands(string $job): array
    {
        $jobs = Yaml::parseFile(static::path('.github/workflows/check.yml'))['jobs'];

        return collect($jobs[$job]['steps'] ?? [])
            ->pluck('run')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Get the path from the repository root.
     */
    protected static function path(string $path): string
    {
        return dirname(__DIR__).'/'.$path;
    }
}
