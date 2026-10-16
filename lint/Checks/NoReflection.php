<?php

namespace ClaudioDekker\Keystone\Lint\Checks;

use ClaudioDekker\Keystone\Lint\Check;
use ClaudioDekker\Keystone\Lint\File;
use PhpParser\Node;

/**
 * Refuse reflection in src.
 */
class NoReflection extends Check
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
        return 'Call the code directly, or give it a protected method to override.';
    }

    /**
     * Get the lines that name a reflection class.
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        return $file->lines($file->find(fn (Node $node) => $node instanceof Node\Name && $this->isReflection($node)));
    }

    /**
     * Determine if the name is one of PHP's reflection classes or the framework's reflector.
     */
    protected function isReflection(Node\Name $name): bool
    {
        return preg_match('/^Reflection[A-Za-z]*$/', $name->toString()) === 1
            || $name->toString() === 'Illuminate\\Support\\Reflector';
    }
}
