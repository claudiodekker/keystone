<?php

namespace ClaudioDekker\Keystone\InertiaVue\Console;

use ClaudioDekker\Keystone\InertiaVue\Install\AppTests;
use ClaudioDekker\Keystone\InertiaVue\Install\Edit;
use ClaudioDekker\Keystone\InertiaVue\Install\Edits\BindKeystoneTestCase;
use ClaudioDekker\Keystone\InertiaVue\Install\Edits\ImplementKeystoneUser;
use ClaudioDekker\Keystone\InertiaVue\Install\Edits\MapAppTests;
use ClaudioDekker\Keystone\InertiaVue\Install\Edits\MergeNpmDependencies;
use ClaudioDekker\Keystone\InertiaVue\Install\Edits\RegisterInertiaMiddleware;
use ClaudioDekker\Keystone\InertiaVue\Install\Edits\RegisterKeystoneTestsuite;
use ClaudioDekker\Keystone\InertiaVue\Install\Edits\RemoveAppJsFromWelcome;
use ClaudioDekker\Keystone\InertiaVue\Install\Edits\RequireRouteFile;
use ClaudioDekker\Keystone\InertiaVue\Install\Edits\SetSessionCookie;
use ClaudioDekker\Keystone\InertiaVue\Install\Edits\UseKeystoneGuard;
use ClaudioDekker\Keystone\InertiaVue\Install\Frontend;
use ClaudioDekker\Keystone\InertiaVue\Install\Stubs;
use ClaudioDekker\Keystone\Keystone;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;

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
    public function handle(Stubs $stubs, Filesystem $files, Repository $config): int
    {
        $manifest = json_decode($files->get($this->laravel->basePath('composer.json')), true);

        if (! is_array($manifest)) {
            return $this->refuse(['Fix composer.json first, because it is not valid JSON.']);
        }

        $refusals = $this->refusals($manifest, $files);

        if ($refusals !== []) {
            return $this->refuse($refusals);
        }

        $this->copy($stubs->for(Keystone::credentialTypes()->names()), $stubs, $files);

        $frontend = new Frontend($this->laravel->basePath(), $stubs, $files);
        $edits = $this->edits($manifest, $config, $files);
        $entriesMoved = true;

        if ($frontend->hasOwnSetup()) {
            $this->printOwnSetup($stubs);
        } else {
            $entriesMoved = $this->setUpInertia($stubs, $files);
            $edits = [...$edits, ...$this->setupEdits($frontend)];
        }

        $everyEditApplied = collect($edits)
            ->map(fn (Edit $edit) => $this->applyEdit($edit, $files))
            ->every(fn (bool $applied) => $applied);

        $everyCommandSucceeded = $this->runCommands();

        $installed = $entriesMoved
            && $everyEditApplied
            && $everyCommandSucceeded;

        return $installed ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Run the commands that make the install take effect, and determine if every one succeeded.
     */
    protected function runCommands(): bool
    {
        $commands = ['composer dump-autoload', 'npm install', 'npm run build'];

        foreach ($commands as $index => $command) {
            $result = Process::path($this->laravel->basePath())
                ->forever()
                ->run($command, fn (string $type, string $output) => $this->output->write($output));

            $this->components->twoColumnDetail($command, $result->successful() ? 'done' : 'failed');

            if ($result->failed()) {
                $this->components->error('Run these yourself once that is fixed:');
                $this->components->bulletList(array_slice($commands, $index));

                return false;
            }
        }

        return true;
    }

    /**
     * Print why Keystone was not installed, and fail.
     *
     * @param  list<string>  $reasons
     */
    protected function refuse(array $reasons): int
    {
        $this->components->error('Keystone was not installed, and nothing in the app changed.');
        $this->components->bulletList($reasons);

        return self::FAILURE;
    }

    /**
     * Get the edits that wire the app up.
     *
     * @param  array<string, mixed>  $manifest
     * @return list<Edit>
     */
    protected function edits(array $manifest, Repository $config, Filesystem $files): array
    {
        $guard = $config->get('auth.defaults.guard');
        $model = $this->userModel($config, $guard);
        $modelFile = $model === null ? null : $this->classFile($manifest, $model);
        $appName = $config->get('app.name');
        $envs = $files->exists($this->laravel->basePath('.env')) ? ['.env.example', '.env'] : ['.env.example'];

        return [
            new RequireRouteFile,
            new ImplementKeystoneUser($model, $modelFile),
            new UseKeystoneGuard($guard),
            ...array_map(fn (string $env) => new SetSessionCookie($env, $appName), $envs),
            new RegisterKeystoneTestsuite,
            new BindKeystoneTestCase,
            new MapAppTests($this->appTests($files)),
            new MergeNpmDependencies($this->npmPackages($files)),
        ];
    }

    /**
     * Get the npm packages the pages need, by name, at the versions this package builds them with.
     *
     * @return array<string, string>
     */
    protected function npmPackages(Filesystem $files): array
    {
        return $files->json(dirname(__DIR__, 2).'/package.json')['dependencies'];
    }

    /**
     * Get the edits that wire the setup stubs into the app.
     *
     * @return list<Edit>
     */
    protected function setupEdits(Frontend $frontend): array
    {
        $edits = [new RegisterInertiaMiddleware];

        if ($frontend->hasWelcomeView()) {
            $edits[] = new RemoveAppJsFromWelcome;
        }

        return $edits;
    }

    /**
     * Get the AppTests path of every Keystone package in the app's vendor, by namespace.
     *
     * @return array<string, string>
     */
    protected function appTests(Filesystem $files): array
    {
        $namespaces = [];

        foreach ($files->glob($this->laravel->basePath(AppTests::GLOB), GLOB_ONLYDIR) as $directory) {
            $package = dirname($directory);
            $namespace = array_key_first($files->json($package.'/composer.json')['autoload']['psr-4']);

            $namespaces[$namespace.'AppTests\\'] = 'vendor/claudiodekker/'.basename($package).'/app-tests/';
        }

        ksort($namespaces);

        return $namespaces;
    }

    /**
     * Get the user model of the guard's provider, or null when there is none.
     */
    protected function userModel(Repository $config, ?string $guard): ?string
    {
        if ($guard === null) {
            return null;
        }

        $provider = $config->get("auth.guards.{$guard}.provider");

        if ($provider === null) {
            return null;
        }

        return $config->get("auth.providers.{$provider}.model");
    }

    /**
     * Get the file of a class through the PSR-4 roots of the app's composer.json, or null when none holds it.
     *
     * @param  array<string, mixed>  $manifest
     */
    protected function classFile(array $manifest, string $class): ?string
    {
        /** @var array<string, string|list<string>> $roots */
        $roots = $manifest['autoload']['psr-4'] ?? [];

        uksort($roots, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        foreach ($roots as $namespace => $directory) {
            if (str_starts_with($class, $namespace)) {
                $root = rtrim(Arr::wrap($directory)[0], '/');
                $relative = str_replace('\\', '/', substr($class, strlen($namespace)));

                return "{$root}/{$relative}.php";
            }
        }

        return null;
    }

    /**
     * Apply the edit and determine if the app has it now.
     */
    protected function applyEdit(Edit $edit, Filesystem $files): bool
    {
        $relative = $edit->path();
        $path = $relative === null ? null : $this->laravel->basePath($relative);

        if ($path === null || ! $files->exists($path)) {
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

        $this->components->twoColumnDetail($relative, $edited === $contents ? 'unchanged' : 'edited');

        return true;
    }

    /**
     * Print why the edit is not in the app, with the change to make by hand.
     */
    protected function printNotApplied(Edit $edit, string $reason): void
    {
        $this->components->twoColumnDetail($edit->path() ?? 'unknown file', $reason);
        $this->newLine();
        $this->line($edit->instructions());
        $this->newLine();
    }

    /**
     * Set Inertia and Vue up from the setup stubs, keeping the skeleton's Vite entry files as .bak.
     *
     * @return bool Whether every Vite entry file is out of the way.
     */
    protected function setUpInertia(Stubs $stubs, Filesystem $files): bool
    {
        $everyEntryMoved = collect(Frontend::VITE_ENTRIES)
            ->map(fn (string $path) => $this->moveAside($path, $files))
            ->every(fn (bool $moved) => $moved);

        $this->copy($stubs->setup(), $stubs, $files);

        return $everyEntryMoved;
    }

    /**
     * Move the file aside to .bak, and determine if it is out of the way.
     */
    protected function moveAside(string $path, Filesystem $files): bool
    {
        $source = $this->laravel->basePath($path);

        if (! $files->exists($source)) {
            return true;
        }

        if ($files->exists($source.'.bak')) {
            $this->components->twoColumnDetail($path, 'drifted');
            $this->newLine();
            $this->line("Remove {$path} by hand, because {$path}.bak keeps the skeleton's copy and the setup replaces this file.");
            $this->newLine();

            return false;
        }

        $files->move($source, $source.'.bak');
        $this->components->twoColumnDetail($path, "moved to {$path}.bak");

        return true;
    }

    /**
     * Print the setup stubs for the app to follow by hand.
     */
    protected function printOwnSetup(Stubs $stubs): void
    {
        $this->components->warn('The app has its own frontend setup, which Keystone keeps. Set Inertia and Vue up in it the way these files do:');
        $this->components->bulletList(array_map($stubs->path(...), $stubs->setup()));
    }

    /**
     * Copy the given stubs into the app.
     *
     * @param  list<string>  $paths
     */
    protected function copy(array $paths, Stubs $stubs, Filesystem $files): void
    {
        $skipped = [];

        foreach ($paths as $path) {
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
