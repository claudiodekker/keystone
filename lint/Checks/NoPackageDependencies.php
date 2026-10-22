<?php

namespace ClaudioDekker\Keystone\Lint\Checks;

use ClaudioDekker\LaravelGuardrails\Check;
use ClaudioDekker\LaravelGuardrails\File;
use ClaudioDekker\LaravelGuardrails\Kind;
use ClaudioDekker\LaravelGuardrails\Violation;
use PhpParser\Node;
use PhpParser\Node\Name;

/**
 * Refuse core that depends on a method or adapter package.
 */
class NoPackageDependencies extends Check
{
    /**
     * Get the one-line description of the rule, shown when the checks are chosen.
     */
    public function description(): string
    {
        return 'No name from another package in the source code of core.';
    }

    /**
     * Get the kinds of code the check reads.
     *
     * @return list<Kind>
     */
    public function kinds(): array
    {
        return [Kind::SOURCE];
    }

    /**
     * Get the one-line description of the files the check reads.
     */
    public function scope(): string
    {
        return 'the source code of packages/keystone';
    }

    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Move the shared code into core, because core never depends on a method or adapter package.';
    }

    /**
     * Determine if the file belongs to core.
     */
    protected function reads(File $file): bool
    {
        return parent::reads($file) && str_starts_with($file->path, 'packages/keystone/');
    }

    /**
     * Get a violation at each name from the namespace of another package.
     *
     * @return list<Violation>
     */
    protected function violations(File $file): array
    {
        $namespaces = $this->packageNamespaces($file);

        return array_map(Violation::at(...), $file->find(fn (Node $node) => $node instanceof Name && $this->isUnder($node, $namespaces)));
    }

    /**
     * Determine if the name sits under one of the namespaces.
     *
     * @param  list<string>  $namespaces
     */
    private function isUnder(Name $name, array $namespaces): bool
    {
        foreach ($namespaces as $namespace) {
            if (str_starts_with($name->toString().'\\', $namespace)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the namespaces every package but core maps in its manifest.
     *
     * @return list<string>
     */
    private function packageNamespaces(File $file): array
    {
        $namespaces = [];

        foreach ($file->project->files('packages/*/composer.json') as $path) {
            if ($path === 'packages/keystone/composer.json') {
                continue;
            }

            $manifest = json_decode((string) $file->project->read($path), true);

            array_push($namespaces, ...array_keys($manifest['autoload']['psr-4'] ?? []));
        }

        return $namespaces;
    }
}
