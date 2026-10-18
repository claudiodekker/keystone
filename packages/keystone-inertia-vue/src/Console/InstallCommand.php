<?php

namespace ClaudioDekker\Keystone\InertiaVue\Console;

use ClaudioDekker\Keystone\InertiaVue\Install\Edit;
use ClaudioDekker\Keystone\InertiaVue\Install\Edits\RequireRouteFile;
use ClaudioDekker\Keystone\InertiaVue\Install\Stubs;
use ClaudioDekker\Keystone\Keystone;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;

/**
 * Install Keystone's sign-in pages into the app as its own files, and wire the app up to run them and their AppTests.
 *
 * Every step converges, so a second run changes nothing and a run that stopped part way is finished by running again.
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

        $everyEditApplied = collect($this->edits())
            ->map(fn (Edit $edit) => $this->applyEdit($edit, $files))
            ->every(fn (bool $applied) => $applied);

        return $everyEditApplied ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Get the edits that wire the app up, in the order they are printed.
     *
     * @return list<Edit>
     */
    protected function edits(): array
    {
        return [
            new RequireRouteFile,
        ];
    }

    /**
     * Apply the edit and determine if the app has it now.
     */
    protected function applyEdit(Edit $edit, Filesystem $files): bool
    {
        $path = $this->laravel->basePath($edit->path());

        if (! $files->exists($path)) {
            $this->printNotApplied($edit, 'missing');

            return false;
        }

        $contents = $files->get($path);
        $edited = $edit->apply($contents);

        if ($edited === null) {
            $this->printNotApplied($edit, 'drifted');

            return false;
        }

        if ($edited !== $contents) {
            $files->put($path, $edited);
        }

        $this->components->twoColumnDetail($edit->path(), $edited === $contents ? 'unchanged' : 'edited');

        return true;
    }

    /**
     * Print why the edit is not in the app, with the change to make by hand.
     */
    protected function printNotApplied(Edit $edit, string $reason): void
    {
        $this->components->twoColumnDetail($edit->path(), $reason);
        $this->components->bulletList([$edit->instructions()]);
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
