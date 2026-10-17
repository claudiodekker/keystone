<?php

namespace ClaudioDekker\Keystone\Lint\Checks;

use ClaudioDekker\Keystone\Lint\Check;
use ClaudioDekker\Keystone\Lint\File;
use PhpParser\Node;
use PhpParser\Node\Stmt;

/**
 * Refuse a final declaration in src.
 */
class NoFinal extends Check
{
    /**
     * The paths the check reads, as fnmatch patterns from the repository root.
     *
     * @var list<string>
     */
    protected array $paths = ['packages/*/src/*.php', 'packages/*/app-tests/*.php', 'packages/*/tests/*.php'];

    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return "Remove final; apps extend Keystone's classes.";
    }

    /**
     * Get the lines that declare a final class, method, constant or property.
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        return $file->lines($file->find(fn (Node $node) => $this->isFinal($node)));
    }

    /**
     * Determine if the node declares something final.
     */
    protected function isFinal(Node $node): bool
    {
        $declarations = [Stmt\Class_::class, Stmt\ClassConst::class, Stmt\ClassMethod::class, Stmt\Property::class];

        return in_array($node::class, $declarations, true) && $node->isFinal();
    }
}
