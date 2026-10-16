<?php

namespace ClaudioDekker\Keystone\Lint\Checks;

use ClaudioDekker\Keystone\Lint\Check;
use ClaudioDekker\Keystone\Lint\File;
use Illuminate\Filesystem\Filesystem;
use PhpParser\Node;

/**
 * Refuse core that depends on a method or adapter package.
 */
class NoPackageDependencies extends Check
{
    /**
     * The paths the check reads, as fnmatch patterns from the repository root.
     *
     * @var list<string>
     */
    protected array $paths = ['packages/keystone/src/*.php'];

    /**
     * The namespaces of the packages core must not use.
     *
     * @var list<string>
     */
    protected array $namespaces;

    /**
     * Create the check, reading the other packages' namespaces from their manifests unless they are given.
     *
     * @param  list<string>|null  $namespaces
     */
    public function __construct(?array $namespaces = null)
    {
        $this->namespaces = $namespaces ?? $this->packageNamespaces();
    }

    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Move the shared code into core; core never depends on a method or adapter package.';
    }

    /**
     * Get the lines that name a class from another package.
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        return $file->lines($file->find(fn (Node $node) => $node instanceof Node\Name && $this->isOtherPackage($node)));
    }

    /**
     * Determine if the name is in the namespace of another package.
     */
    protected function isOtherPackage(Node\Name $name): bool
    {
        return collect($this->namespaces)->contains(fn (string $namespace) => str_starts_with($name->toString().'\\', $namespace));
    }

    /**
     * Get the namespaces every package but core maps in its manifest.
     *
     * @return list<string>
     */
    protected function packageNamespaces(): array
    {
        $files = new Filesystem;

        return collect($files->glob(dirname(__DIR__, 2).'/packages/*/composer.json'))
            ->reject(fn (string $path) => basename(dirname($path)) === 'keystone')
            ->flatMap(fn (string $path) => array_keys($files->json($path)['autoload']['psr-4'] ?? []))
            ->values()
            ->all();
    }
}
