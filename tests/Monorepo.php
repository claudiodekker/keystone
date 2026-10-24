<?php

namespace ClaudioDekker\Keystone\Tests;

use Composer\InstalledVersions;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use SimpleXMLElement;
use Symfony\Component\Yaml\Yaml;

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
    public static function packagesExceptCore(): array
    {
        return collect(static::packages())
            ->reject(fn (string $package) => $package === 'keystone')
            ->values()
            ->all();
    }

    /**
     * Get the directory name of every frontend adapter, which is every package with stubs to install.
     *
     * @return list<string>
     */
    public static function adapters(): array
    {
        return collect(static::packages())
            ->filter(fn (string $package) => is_dir(static::path("packages/{$package}/stubs")))
            ->values()
            ->all();
    }

    /**
     * Get the directory name of every frontend adapter Composer installed, whose stubs can therefore load.
     *
     * @return list<string>
     */
    public static function installedAdapters(): array
    {
        return collect(static::adapters())
            ->filter(fn (string $adapter) => InstalledVersions::isInstalled(static::manifest($adapter)['name']))
            ->values()
            ->all();
    }

    /**
     * Get the directory name of every method package, which is every package but core and the adapters.
     *
     * @return list<string>
     */
    public static function methodPackages(): array
    {
        return collect(static::packagesExceptCore())
            ->diff(static::adapters())
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
     * Get the namespace the repository's autoload-dev maps the package's AppTests to, or null when it maps none.
     */
    public static function appTestsNamespace(string $package): ?string
    {
        $namespace = array_search("packages/{$package}/app-tests/", static::manifest()['autoload-dev']['psr-4'], true);

        return $namespace === false ? null : $namespace;
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
