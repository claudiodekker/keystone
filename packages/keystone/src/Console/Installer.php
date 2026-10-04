<?php

namespace ClaudioDekker\Keystone\Console;

use Closure;
use Composer\InstalledVersions;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use ParseError;
use stdClass;
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
        'claudiodekker/keystone-totp' => 'ClaudioDekker\\Keystone\\Totp\\AppTests\\',
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
     * Make the user model implement KeystoneUser and use HasKeystone, reporting false when its file can't be edited safely.
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

        $edited = $this->withKeystoneUser($source, class_basename($model));

        if ($edited === null || ! $this->parses($edited)) {
            return false;
        }

        $this->files->put($path, $edited);

        return true;
    }

    /**
     * Get the model's source with KeystoneUser implemented and HasKeystone used, or null when it isn't shaped the way every step can match.
     */
    protected function withKeystoneUser(string $source, string $class): ?string
    {
        $imported = $this->replaceOnce('/^use /m', "use ClaudioDekker\\Keystone\\HasKeystone;\nuse ClaudioDekker\\Keystone\\KeystoneUser;\nuse ", $source);

        if ($imported === null) {
            return null;
        }

        $implemented = $this->replaceOnce("/^(class {$class} extends [\\w\\\\]+) implements ([\\w\\\\]+(?:, *[\\w\\\\]+)*)[ \\t]*$/m", '$1 implements $2, KeystoneUser', $imported)
            ?? $this->replaceOnce("/^(class {$class} extends [\\w\\\\]+)[ \\t]*$/m", '$1 implements KeystoneUser', $imported);

        if ($implemented === null) {
            return null;
        }

        return $this->replaceOnce('/^(    use [\\w\\\\]+(?:, *[\\w\\\\]+)*);$/m', '$1, HasKeystone;', $implemented)
            ?? $this->replaceOnce("/^(class {$class} .+\\n\\{\\n)/m", "\$1    use HasKeystone;\n\n", $implemented);
    }

    /**
     * Replace the first match of the pattern, or get null when there is none.
     */
    protected function replaceOnce(string $pattern, string $replacement, string $subject): ?string
    {
        $replaced = preg_replace($pattern, $replacement, $subject, limit: 1, count: $count);

        return $count === 1 ? $replaced : null;
    }

    /**
     * Determine if the PHP source parses.
     */
    protected function parses(string $source): bool
    {
        try {
            return token_get_all($source, TOKEN_PARSE) !== [];
        } catch (ParseError) {
            return false;
        }
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
        $suite = '/<testsuite\s+name="Keystone"\s*(?:\/>|>(.*?)<\/testsuite>)/s';

        if (preg_match($suite, $xml) !== 1) {
            if (! str_contains($xml, '</testsuites>')) {
                return false;
            }

            $xml = str_replace('</testsuites>', "    <testsuite name=\"Keystone\">\n        </testsuite>\n    </testsuites>", $xml);
        }

        $xml = preg_replace_callback($suite, function (array $match) use ($directories) {
            $body = rtrim($match[1] ?? '');
            $missing = array_filter($directories, fn (string $directory) => ! str_contains($body, "<directory>{$directory}</directory>"));

            if ($missing === []) {
                return $match[0];
            }

            foreach ($missing as $directory) {
                $body .= "\n            <directory>{$directory}</directory>";
            }

            return "<testsuite name=\"Keystone\">{$body}\n        </testsuite>";
        }, $xml, limit: 1) ?? $xml;

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
        $this->editJson('composer.json', function (stdClass $manifest) use ($namespaces) {
            $autoload = $manifest->{'autoload-dev'} ??= new stdClass;
            $mapped = (array) ($autoload->{'psr-4'} ?? []);

            $autoload->{'psr-4'} = (object) [...$mapped, ...array_diff_key($namespaces, $mapped)];
        });
    }

    /**
     * Add the packages to the app's npm devDependencies, unless it already depends on them at any version.
     *
     * @param  array<string, string>  $packages
     */
    public function addNpmPackages(array $packages): void
    {
        $this->editJson('package.json', function (stdClass $manifest) use ($packages) {
            $devDependencies = (array) ($manifest->devDependencies ?? []);
            $installed = [...(array) ($manifest->dependencies ?? []), ...$devDependencies, ...(array) ($manifest->peerDependencies ?? [])];
            $missing = array_diff_key($packages, $installed);

            if ($missing === []) {
                return;
            }

            $devDependencies = [...$devDependencies, ...$missing];
            ksort($devDependencies);

            $manifest->devDependencies = (object) $devDependencies;
        });
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
     * Edit a JSON file of the app, keeping its indentation and leaving the file alone when the edit changes nothing.
     *
     * @param  Closure(stdClass): void  $edit
     */
    protected function editJson(string $path, Closure $edit): void
    {
        $json = $this->files->exists($this->path($path)) ? $this->files->get($this->path($path)) : '{}';
        $manifest = json_decode($json, flags: JSON_THROW_ON_ERROR);
        $before = json_encode($manifest, JSON_THROW_ON_ERROR);

        $edit($manifest);

        if (json_encode($manifest, JSON_THROW_ON_ERROR) === $before) {
            return;
        }

        $edited = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $indent = preg_match('/^([ \t]+)"/m', $json, $match) === 1 ? $match[1] : '    ';

        $indented = preg_replace_callback('/^(?: {4})+/m', fn (array $spaces) => str_repeat($indent, intdiv(strlen($spaces[0]), 4)), $edited);

        $this->files->put($this->path($path), $indented."\n");
    }
}
