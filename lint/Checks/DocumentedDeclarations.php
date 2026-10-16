<?php

namespace ClaudioDekker\Keystone\Lint\Checks;

use ClaudioDekker\Keystone\Lint\Check;
use ClaudioDekker\Keystone\Lint\File;
use PhpParser\Node;
use PhpParser\Node\Stmt;

/**
 * Require a docblock on every class, function and property in src and in test support code.
 */
class DocumentedDeclarations extends Check
{
    /**
     * The paths the check reads, as fnmatch patterns from the repository root.
     *
     * @var list<string>
     */
    protected array $paths = ['packages/*/src/*.php', 'packages/*/app-tests/*.php', 'packages/*/tests/*.php', 'tests/*.php', 'lint/*.php'];

    /**
     * The paths the check skips.
     *
     * @var list<string>
     */
    protected array $except = ['*Test.php'];

    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Add a docblock: one imperative line saying what it does.';
    }

    /**
     * Get the lines that declare something without a docblock.
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        return $file->lines($file->find(fn (Node $node) => $this->needsDocblock($node) && $node->getDocComment() === null));
    }

    /**
     * Determine if the node is a declaration that needs a docblock.
     */
    protected function needsDocblock(Node $node): bool
    {
        if ($node instanceof Stmt\ClassLike) {
            return $node->name !== null;
        }

        return $node instanceof Stmt\ClassMethod
            || $node instanceof Stmt\Function_
            || $node instanceof Stmt\Property;
    }
}
