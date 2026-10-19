<?php

namespace ClaudioDekker\Keystone\InertiaVue\Console;

use ClaudioDekker\Keystone\InertiaVue\Install\AppTests;
use ClaudioDekker\Keystone\InertiaVue\Install\Edit;
use ClaudioDekker\Keystone\InertiaVue\Install\Edits\BindKeystoneTestCase;
use ClaudioDekker\Keystone\InertiaVue\Install\Edits\MapAppTests;
use ClaudioDekker\Keystone\InertiaVue\Install\Edits\MergeNpmDependencies;
use ClaudioDekker\Keystone\InertiaVue\Install\Edits\RegisterKeystoneTestsuite;
use ClaudioDekker\Keystone\InertiaVue\Install\Edits\RequireRouteFile;
use ClaudioDekker\Keystone\InertiaVue\Install\Edits\SetSessionCookie;
use ClaudioDekker\Keystone\InertiaVue\Install\Edits\UseKeystoneGuard;
use ClaudioDekker\Keystone\InertiaVue\Install\Stubs;
use ClaudioDekker\Keystone\Keystone;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
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

        $this->copy($stubs, $files);
        $this->replaceSkeletonFiles($stubs, $files);

        $everyEditApplied = collect($this->edits($config, $files))
            ->map(fn (Edit $edit) => $this->applyEdit($edit, $files))
            ->every(fn (bool $applied) => $applied);

        $everyCommandSucceeded = $this->runCommands();

        return $everyEditApplied && $everyCommandSucceeded ? self::SUCCESS : self::FAILURE;
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
     * @return list<Edit>
     */
    protected function edits(Repository $config, Filesystem $files): array
    {
        $guard = $config->get('auth.defaults.guard');
        $appName = $config->get('app.name');
        $envs = $files->exists($this->laravel->basePath('.env')) ? ['.env.example', '.env'] : ['.env.example'];

        return [
            new RequireRouteFile,
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
     * Replace the skeleton's files that Keystone needs changed, and delete the frontend files the stubs replace.
     */
    protected function replaceSkeletonFiles(Stubs $stubs, Filesystem $files): void
    {
        foreach ($stubs->replacements() as $path => $name) {
            $target = $this->laravel->basePath($path);

            if ($files->exists($target) && str_contains($files->get($target), $name)) {
                continue;
            }

            $files->ensureDirectoryExists(dirname($target));
            $files->copy($stubs->path($path), $target);

            $this->components->twoColumnDetail($path, 'replaced');
        }

        foreach (['vite.config.js', 'resources/js/app.js'] as $path) {
            if ($files->delete($this->laravel->basePath($path))) {
                $this->components->twoColumnDetail($path, 'deleted');
            }
        }

        $welcome = $this->laravel->basePath('resources/views/welcome.blade.php');

        if ($files->exists($welcome)) {
            $files->replaceInFile("'resources/css/app.css', 'resources/js/app.js'", "'resources/css/app.css'", $welcome);
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
