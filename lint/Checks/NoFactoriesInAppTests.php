<?php

namespace ClaudioDekker\Keystone\Lint\Checks;

use ClaudioDekker\Keystone\Lint\Check;
use ClaudioDekker\Keystone\Lint\File;
use PhpParser\Node;
use PhpParser\Node\Expr;

/**
 * Refuse a model factory called in an AppTest, which would skip the model the app swapped in.
 */
class NoFactoriesInAppTests extends Check
{
    /**
     * The paths the check reads, as fnmatch patterns from the repository root.
     *
     * @var list<string>
     */
    protected array $paths = ['packages/*/app-tests/*Test.php'];

    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Create the record through a harness helper, such as createUser() or addVerifiedAddress().';
    }

    /**
     * Get the lines that call factory() on a class.
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        return $file->lines($file->find(fn (Node $node) => $this->isFactoryCall($node)));
    }

    /**
     * Determine if the node calls factory() on a class, such as UserEmail::factory().
     */
    protected function isFactoryCall(Node $node): bool
    {
        return $node instanceof Expr\StaticCall
            && $node->name instanceof Node\Identifier
            && $node->name->toLowerString() === 'factory';
    }
}
