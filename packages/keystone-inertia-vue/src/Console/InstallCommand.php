<?php

namespace ClaudioDekker\Keystone\InertiaVue\Console;

use ClaudioDekker\Keystone\InertiaVue\Install\Stubs;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * Install Keystone's sign-in pages into the app as its own files.
 *
 * @internal
 */
class InstallCommand extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'keystone:install';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Install Keystone's Inertia and Vue pages into the app";

    /**
     * Copy the stubs into the app.
     */
    public function handle(Stubs $stubs, Filesystem $files): int
    {
        foreach ($stubs->paths() as $path) {
            $target = $this->laravel->basePath($path);

            $files->ensureDirectoryExists(dirname($target));
            $files->copy($stubs->path($path), $target);

            $this->components->twoColumnDetail($path, 'created');
        }

        return self::SUCCESS;
    }
}
