<?php

namespace ClaudioDekker\Keystone\InertiaVue\Console;

use ClaudioDekker\Keystone\InertiaVue\Install\Stubs;
use ClaudioDekker\Keystone\Keystone;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;

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
    protected $signature = 'keystone:install {--force : Overwrite the Keystone files the app already has}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Install Keystone's Inertia and Vue pages into the app";

    /**
     * Install, after refusing an app that can't take Keystone before anything changes.
     */
    public function handle(Stubs $stubs, Filesystem $files): int
    {
        $refusals = $this->refusals($files->json($this->laravel->basePath('composer.json')), $files);

        if ($refusals !== []) {
            $this->components->error('Keystone was not installed, and nothing in the app changed.');
            $this->components->bulletList($refusals);

            return self::FAILURE;
        }

        $this->copy($stubs, $files);

        return self::SUCCESS;
    }

    /**
     * Copy the stubs the app lacks, or every stub when forced.
     */
    protected function copy(Stubs $stubs, Filesystem $files): void
    {
        $skipped = [];

        foreach ($stubs->for(Keystone::credentialTypes()->names()) as $path) {
            $target = $this->laravel->basePath($path);

            if ($files->exists($target) && ! $this->option('force')) {
                $skipped[] = $path;

                continue;
            }

            $files->ensureDirectoryExists(dirname($target));
            $files->copy($stubs->path($path), $target);

            $this->components->twoColumnDetail($path, 'created');
        }

        if ($skipped !== []) {
            $this->components->warn('Skipped, because the app has them already (run with --force to overwrite):');
            $this->components->bulletList($skipped);
        }
    }

    /**
     * Get the reasons the app can't take Keystone.
     *
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    protected function refusals(array $manifest, Filesystem $files): array
    {
        $packages = $this->packages($manifest, $files);
        $refusals = [];

        if ($packages->contains('laravel/fortify')) {
            $refusals[] = 'Remove laravel/fortify, and any package that requires it, first, because Keystone replaces it.';
        }

        if (! $packages->contains('pestphp/pest')) {
            $refusals[] = "Install Pest first, because Keystone's AppTests run on it.";
        }

        return $refusals;
    }

    /**
     * Get the name of every package the app requires or has installed.
     *
     * @param  array<string, mixed>  $manifest
     * @return Collection<int, string>
     */
    protected function packages(array $manifest, Filesystem $files): Collection
    {
        $required = array_merge($manifest['require'] ?? [], $manifest['require-dev'] ?? []);
        $path = $this->laravel->basePath('vendor/composer/installed.json');
        /** @var list<array{name: string}> $installed */
        $installed = $files->exists($path) ? $files->json($path)['packages'] ?? [] : [];

        return collect(array_keys($required))->merge(collect($installed)->pluck('name'));
    }
}
