<?php

namespace ClaudioDekker\Keystone\Lint\Checks;

use ClaudioDekker\Keystone\Lint\Check;
use ClaudioDekker\Keystone\Lint\File;
use PhpParser\Node;
use PhpParser\Node\Expr;

/**
 * Refuse eval() in src.
 */
class NoEval extends Check
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
        return 'Remove eval() and write the code out.';
    }

    /**
     * Get the lines that call eval().
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        return $file->lines($file->find(fn (Node $node) => $node instanceof Expr\Eval_));
    }
}
