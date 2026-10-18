<?php

namespace ClaudioDekker\Keystone\InertiaVue\Tests;

use Composer\InstalledVersions;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/**
 * A copy of the Laravel skeleton as `laravel new --pest` leaves it, for the installer to install into.
 */
class FreshApp
{
    /**
     * Create the view of the copy at the given path.
     */
    public function __construct(protected string $path, protected Filesystem $files = new Filesystem)
    {
        //
    }

    /**
     * Copy the skeleton Composer installed to a new directory, with Pest in place of PHPUnit.
     */
    public static function copy(): static
    {
        $files = new Filesystem;
        $path = sys_get_temp_dir().'/keystone-'.Str::random(8);

        $files->copyDirectory(InstalledVersions::getInstallPath('laravel/laravel'), $path);
        $files->copy(InstalledVersions::getInstallPath('pestphp/pest').'/stubs/init-laravel/Pest.php.stub', $path.'/tests/Pest.php');

        $app = new static($path, $files);

        return $app->remove('phpunit/phpunit')->requireDev('pestphp/pest')->requireDev('pestphp/pest-plugin-laravel');
    }

    /**
     * Get the path of the copy, or of a file in it.
     */
    public function path(string $path = ''): string
    {
        return $path === '' ? $this->path : $this->path.'/'.$path;
    }

    /**
     * Get the contents of a file in the copy.
     */
    public function contents(string $path): string
    {
        return $this->files->get($this->path($path));
    }

    /**
     * Determine if the copy has the file.
     */
    public function has(string $path): bool
    {
        return $this->files->exists($this->path($path));
    }

    /**
     * Write a file in the copy.
     *
     * @return $this
     */
    public function put(string $path, string $contents): static
    {
        $this->files->ensureDirectoryExists(dirname($this->path($path)));
        $this->files->put($this->path($path), $contents);

        return $this;
    }

    /**
     * Add a package to the copy's require.
     *
     * @return $this
     */
    public function require(string $package): static
    {
        $manifest = $this->manifest();
        $manifest['require'][$package] = '*';

        return $this->putManifest($manifest);
    }

    /**
     * Add a package to the copy's require-dev.
     *
     * @return $this
     */
    public function requireDev(string $package): static
    {
        $manifest = $this->manifest();
        $manifest['require-dev'][$package] = '*';

        return $this->putManifest($manifest);
    }

    /**
     * Drop a package from the copy's require and require-dev.
     *
     * @return $this
     */
    public function remove(string $package): static
    {
        $manifest = $this->manifest();
        unset($manifest['require'][$package], $manifest['require-dev'][$package]);

        return $this->putManifest($manifest);
    }

    /**
     * Delete the copy.
     */
    public function delete(): void
    {
        $this->files->deleteDirectory($this->path);
    }

    /**
     * Get the copy's decoded composer.json.
     *
     * @return array<string, mixed>
     */
    protected function manifest(): array
    {
        return $this->files->json($this->path('composer.json'));
    }

    /**
     * Write the copy's composer.json.
     *
     * @param  array<string, mixed>  $manifest
     * @return $this
     */
    protected function putManifest(array $manifest): static
    {
        return $this->put('composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }
}
