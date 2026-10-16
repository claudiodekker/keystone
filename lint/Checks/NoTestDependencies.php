<?php

namespace ClaudioDekker\Keystone\Lint\Checks;

use ClaudioDekker\Keystone\Lint\Check;
use ClaudioDekker\Keystone\Lint\File;
use PhpParser\Node;

/**
 * Refuse src that depends on tests.
 */
class NoTestDependencies extends Check
{
    /**
     * The paths the check reads, as fnmatch patterns from the repository root.
     *
     * @var list<string>
     */
    protected array $paths = ['packages/*/src/*.php'];

    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Move what src needs out of tests and into src.';
    }

    /**
     * Get the lines that name a class from a tests namespace.
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        return $file->lines($file->find(fn (Node $node) => $node instanceof Node\Name && $this->isTestClass($node)));
    }

    /**
     * Determine if the name is in a tests namespace of Keystone.
     */
    protected function isTestClass(Node\Name $name): bool
    {
        return str_starts_with($name->toString(), 'ClaudioDekker\\Keystone\\')
            && in_array('Tests', $name->getParts(), true);
    }
}
