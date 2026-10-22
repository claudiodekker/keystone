<?php

namespace ClaudioDekker\Keystone\Tests;

use ClaudioDekker\Keystone\KeystoneServiceProvider;
use Closure;
use Composer\InstalledVersions;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Run real artisan commands in a copy of the Laravel skeleton that boots Keystone with a published config file the test writes.
 */
class ArtisanApp
{
    /**
     * Hold the path of the app's copy of the skeleton.
     */
    protected function __construct(protected string $path)
    {
        //
    }

    /**
     * Give the callback a fresh copy of the app, then delete the copy.
     *
     * @param  array<string, mixed>  $keystone  the contents of the copy's config/keystone.php
     * @param  Closure(static): mixed  $callback
     */
    public static function using(array $keystone, Closure $callback): void
    {
        $app = new static(static::copy($keystone));

        try {
            $callback($app);
        } finally {
            File::deleteDirectory($app->path);
        }
    }

    /**
     * Run the artisan command in the app.
     */
    public function artisan(string $command): ProcessResult
    {
        return Process::path($this->path)
            ->env(['APP_ENV' => 'local', 'CACHE_STORE' => 'array'])
            ->run([PHP_BINARY, 'artisan', $command]);
    }

    /**
     * Cache the app's config, then write the Keystone values over the cached ones, as a hand edit of the cache would.
     *
     * @param  array<string, mixed>  $keystone
     */
    public function cacheConfigEditedBy(array $keystone): void
    {
        $this->artisan('config:cache');

        $config = require $this->cachedConfigPath();
        $config['keystone'] = array_replace_recursive($config['keystone'], $keystone);

        File::put($this->cachedConfigPath(), "<?php\n\nreturn ".var_export($config, true).";\n");
    }

    /**
     * Determine if the app holds a cached config.
     */
    public function hasCachedConfig(): bool
    {
        return File::exists($this->cachedConfigPath());
    }

    /**
     * Get the path of the app's cached config.
     */
    protected function cachedConfigPath(): string
    {
        return $this->path.'/bootstrap/cache/config.php';
    }

    /**
     * Copy the skeleton to a new directory that serves Keystone from this repository's vendor, and get its path.
     *
     * @param  array<string, mixed>  $keystone
     */
    protected static function copy(array $keystone): string
    {
        $path = sys_get_temp_dir().'/keystone-artisan-'.Str::random(8);
        $provider = KeystoneServiceProvider::class;

        File::copyDirectory(InstalledVersions::getInstallPath('laravel/laravel'), $path);
        File::link(dirname(__DIR__).'/vendor', $path.'/vendor');
        File::put($path.'/bootstrap/providers.php', "<?php\n\nreturn [\n    {$provider}::class,\n];\n");
        File::put($path.'/config/keystone.php', "<?php\n\nreturn ".var_export($keystone, true).";\n");

        return $path;
    }
}
