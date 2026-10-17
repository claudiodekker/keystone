<?php

namespace ClaudioDekker\Keystone\Lint\Checks;

use ClaudioDekker\Keystone\Lint\Check;
use ClaudioDekker\Keystone\Lint\File;
use PhpParser\Node;
use PhpParser\Node\Stmt;

/**
 * Refuse a function declared in an AppTest, which would collide with the app's own when its suite loads both.
 */
class NoGlobalFunctionsInAppTests extends Check
{
    /**
     * The paths the check reads, as fnmatch patterns from the repository root.
     *
     * @var list<string>
     */
    protected array $paths = ['packages/*/app-tests/*.php'];

    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Move the function onto the AppTest harness as a named helper, with its own test.';
    }

    /**
     * Get the lines that declare a function.
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        return $file->lines($file->find(fn (Node $node) => $node instanceof Stmt\Function_));
    }
}
