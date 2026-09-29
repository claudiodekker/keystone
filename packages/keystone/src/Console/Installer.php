<?php

namespace ClaudioDekker\Keystone\Console;

use Closure;
use Composer\InstalledVersions;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Finder\SplFileInfo;

/**
 * @internal
 */
class Installer
{
    /**
     * The folders under a stubs folder that are generated at build, never copied.
     */
    protected const array GENERATED = ['resources/js/actions/', 'resources/js/routes/', 'resources/js/wayfinder/'];

    /**
     * The namespace of each Keystone package's AppTests.
     */
    protected const array APP_TESTS = [
        'claudiodekker/keystone' => 'ClaudioDekker\\Keystone\\AppTests\\',
        'claudiodekker/keystone-password' => 'ClaudioDekker\\Keystone\\Password\\AppTests\\',
        'claudiodekker/keystone-inertia-vue' => 'ClaudioDekker\\Keystone\\InertiaVue\\AppTests\\',
    ];

    /**
     * Create a new installer for the app at the base path.
     */
    public function __construct(
        protected Filesystem $files,
        protected string $basePath,
    ) {
        //
    }

    /**
     * Get why Keystone can't be installed in the app, if it can't.
     */
    public function refusal(): ?string
    {
        $manifest = $this->readJson('composer.json');
        $packages = [...$manifest['require'] ?? [], ...$manifest['require-dev'] ?? []];

        if (isset($packages['laravel/fortify'])) {
            return 'Remove laravel/fortify first (composer remove laravel/fortify): Keystone replaces it, and the two would both handle authentication.';
        }

        if (! isset($packages['pestphp/pest'])) {
            return "Install Pest first (composer require pestphp/pest --dev): Keystone's AppTests run on it.";
        }

        return null;
    }

    /**
     * Copy the stubs the filter accepts into the app, skipping the files it already has unless forced.
     *
     * @param  Closure(string): bool  $accepts
     * @return array{copied: list<string>, skipped: list<string>}
     */
    public function copyStubs(string $stubs, bool $force, Closure $accepts): array
    {
        $copied = [];
        $skipped = [];

        foreach ($this->stubFiles($stubs) as $path) {
            if (! $accepts($path)) {
                continue;
            }

            $target = $this->path($path);

            if ($this->files->exists($target) && ! $force) {
                $skipped[] = $path;

                continue;
            }

            $this->files->ensureDirectoryExists(dirname($target));
            $this->files->copy("{$stubs}/{$path}", $target);
            $copied[] = $path;
        }

        return ['copied' => $copied, 'skipped' => $skipped];
    }

    /**
     * Get the paths of the stub files, relative to the stubs folder, leaving out what is generated at build.
     *
     * @return list<string>
     */
    protected function stubFiles(string $stubs): array
    {
        $paths = array_map(
            fn (SplFileInfo $file) => str_replace('\\', '/', $file->getRelativePathname()),
            $this->files->allFiles($stubs, hidden: true),
        );

        $kept = array_filter($paths, fn (string $path) => ! Str::startsWith($path, self::GENERATED));
        sort($kept);

        return $kept;
    }

    /**
     * Move the app's file aside to a .bak copy.
     */
    public function backUp(string $path): bool
    {
        if (! $this->files->exists($this->path($path))) {
            return false;
        }

        return $this->files->move($this->path($path), $this->path("{$path}.bak"));
    }

    /**
     * Require the route file from routes/web.php, unless it already does.
     */
    public function requireRouteFile(string $file): void
    {
        $line = "require __DIR__.'/{$file}';";
        $web = $this->files->get($this->path('routes/web.php'));

        if (! str_contains($web, $line)) {
            $this->files->put($this->path('routes/web.php'), rtrim($web)."\n\n{$line}\n");
        }
    }

    /**
     * Make the user model implement KeystoneUser and use HasKeystone, reporting false when its file can't be edited.
     */
    public function makeKeystoneUser(string $model): bool
    {
        $path = $this->path('app/'.str_replace('\\', '/', Str::after($model, 'App\\')).'.php');

        if (! $this->files->exists($path)) {
            return false;
        }

        $source = $this->files->get($path);

        if (str_contains($source, 'HasKeystone')) {
            return true;
        }

        $class = class_basename($model);
        $imports = "use ClaudioDekker\\Keystone\\HasKeystone;\nuse ClaudioDekker\\Keystone\\KeystoneUser;\n";

        $patterns = [
            '/^use /m' => $imports.'use ',
            "/^(class {$class} extends [\\w\\\\]+) implements ([^\\n{]+?)\\s*$/m" => '$1 implements $2, KeystoneUser',
            "/^(class {$class} extends [\\w\\\\]+)\\s*$/m" => '$1 implements KeystoneUser',
            '/^(    use [^;(]+);/m' => '$1, HasKeystone;',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $source = preg_replace($pattern, $replacement, $source, limit: 1) ?? $source;
        }

        if (! str_contains($source, 'implements KeystoneUser') && ! str_contains($source, ', KeystoneUser')) {
            return false;
        }

        if (! str_contains($source, ', HasKeystone;')) {
            $source = preg_replace('/^(class .+\n\{\n)/m', "\$1    use HasKeystone;\n\n", $source, limit: 1) ?? $source;
        }

        $this->files->put($path, $source);

        return true;
    }

    /**
     * Set the web guard's driver to keystone, reporting false when config/auth.php can't be edited.
     */
    public function useKeystoneGuard(): bool
    {
        $path = $this->path('config/auth.php');
        $source = $this->files->exists($path) ? $this->files->get($path) : '';
        $guard = "/('web'\\s*=>\\s*\\[\\s*'driver'\\s*=>\\s*)'(\\w+)'/";

        if (preg_match($guard, $source) !== 1) {
            return false;
        }

        $this->files->put($path, preg_replace($guard, "\$1'keystone'", $source, limit: 1) ?? $source);

        return true;
    }

    /**
     * Set the variables in the app's .env and .env.example, replacing any value they already have.
     *
     * @param  array<string, string>  $variables
     */
    public function setEnvironment(array $variables): void
    {
        foreach (['.env', '.env.example'] as $file) {
            if (! $this->files->exists($this->path($file))) {
                continue;
            }

            $env = $this->files->get($this->path($file));

            foreach ($variables as $name => $value) {
                $line = "{$name}={$value}";
                $env = preg_match("/^{$name}=.*$/m", $env) === 1
                    ? (preg_replace("/^{$name}=.*$/m", $line, $env) ?? $env)
                    : rtrim($env)."\n{$line}\n";
            }

            $this->files->put($this->path($file), $env);
        }
    }

    /**
     * Get a variable from the app's .env, unquoted.
     */
    public function environment(string $name, string $default): string
    {
        $env = $this->files->exists($this->path('.env')) ? $this->files->get($this->path('.env')) : '';

        if (preg_match("/^{$name}=(.*)$/m", $env, $match) !== 1) {
            return $default;
        }

        return trim($match[1], " \t\"'");
    }

    /**
     * Add the directories to phpunit.xml's Keystone testsuite, reporting false when phpunit.xml can't be edited.
     *
     * @param  list<string>  $directories
     */
    public function registerTestsuite(array $directories): bool
    {
        $path = $this->path('phpunit.xml');
        $xml = $this->files->exists($path) ? $this->files->get($path) : '';

        if (! str_contains($xml, '<testsuite name="Keystone">')) {
            if (! str_contains($xml, '</testsuites>')) {
                return false;
            }

            $xml = str_replace('</testsuites>', "    <testsuite name=\"Keystone\">\n        </testsuite>\n    </testsuites>", $xml);
        }

        foreach ($directories as $directory) {
            if (! str_contains($xml, "<directory>{$directory}</directory>")) {
                $xml = preg_replace(
                    '/(<testsuite name="Keystone">.*?)(\n\s*<\/testsuite>)/s',
                    "\$1\n            <directory>{$directory}</directory>\$2",
                    $xml,
                    limit: 1,
                ) ?? $xml;
            }
        }

        $this->files->put($path, $xml);

        return true;
    }

    /**
     * Append the middleware to the app's web group in bootstrap/app.php, reporting false when the file can't be edited.
     */
    public function appendWebMiddleware(string $middleware): bool
    {
        $path = $this->path('bootstrap/app.php');
        $source = $this->files->exists($path) ? $this->files->get($path) : '';

        if (str_contains($source, "\\{$middleware}::class")) {
            return true;
        }

        $placeholder = "->withMiddleware(function (Middleware \$middleware): void {\n        //\n";

        if (! str_contains($source, $placeholder)) {
            return false;
        }

        $appended = "->withMiddleware(function (Middleware \$middleware): void {\n        \$middleware->web(append: [\n            \\{$middleware}::class,\n        ]);\n";
        $this->files->put($path, str_replace($placeholder, $appended, $source));

        return true;
    }

    /**
     * Get the folder of each installed Keystone package's AppTests, keyed by namespace.
     *
     * @return array<string, string>
     */
    public function appTests(): array
    {
        $installed = array_filter(array_keys(self::APP_TESTS), fn (string $package) => InstalledVersions::isInstalled($package));

        return array_combine(
            array_map(fn (string $package) => self::APP_TESTS[$package], $installed),
            array_map(fn (string $package) => "vendor/{$package}/app-tests/", $installed),
        );
    }

    /**
     * Replace the text in the app's file, if the file has it.
     */
    public function replaceIn(string $path, string $search, string $replace): void
    {
        if (! $this->has($path)) {
            return;
        }

        $this->files->replaceInFile($search, $replace, $this->path($path));
    }

    /**
     * Map the namespaces in the app's autoload-dev, keeping what it maps already.
     *
     * @param  array<string, string>  $namespaces
     */
    public function mapAutoloadDev(array $namespaces): void
    {
        $manifest = $this->readJson('composer.json');
        $mapped = $manifest['autoload-dev']['psr-4'] ?? [];
        $manifest['autoload-dev']['psr-4'] = [...$mapped, ...array_diff_key($namespaces, $mapped)];

        $this->writeJson('composer.json', $manifest);
    }

    /**
     * Add the packages to the app's npm devDependencies, unless it already depends on them at any version.
     *
     * @param  array<string, string>  $packages
     */
    public function addNpmPackages(array $packages): void
    {
        $manifest = $this->readJson('package.json');
        $devDependencies = [...$manifest['devDependencies'] ?? []];
        $installed = [...$manifest['dependencies'] ?? [], ...$devDependencies, ...$manifest['peerDependencies'] ?? []];

        foreach (array_diff_key($packages, $installed) as $package => $version) {
            $devDependencies[$package] = $version;
        }

        ksort($devDependencies);
        $manifest['devDependencies'] = $devDependencies;

        $this->writeJson('package.json', $manifest);
    }

    /**
     * Determine if the app has the file.
     */
    public function has(string $path): bool
    {
        return $this->files->exists($this->path($path));
    }

    /**
     * Get the absolute path of a path in the app.
     */
    public function path(string $path = ''): string
    {
        return rtrim($this->basePath.'/'.$path, '/');
    }

    /**
     * Read a JSON file of the app.
     *
     * @return array<string, mixed>
     */
    protected function readJson(string $path): array
    {
        $json = $this->files->exists($this->path($path)) ? $this->files->get($this->path($path)) : '{}';

        return (array) json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Write a JSON file of the app the way composer and npm do.
     *
     * @param  array<string, mixed>  $data
     */
    protected function writeJson(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->files->put($this->path($path), $json."\n");
    }
}
