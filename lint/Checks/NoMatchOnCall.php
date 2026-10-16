<?php

namespace ClaudioDekker\Keystone\Lint\Checks;

use ClaudioDekker\Keystone\Lint\Check;
use ClaudioDekker\Keystone\Lint\File;
use PhpParser\Node;
use PhpParser\Node\Expr;

/**
 * Refuse a match whose subject is a call.
 */
class NoMatchOnCall extends Check
{
    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return "Name the call's result in a variable, then match on the variable.";
    }

    /**
     * Get the lines of the matches on a call.
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        return $file->lines($file->find(fn (Node $node) => $node instanceof Expr\Match_ && $node->cond instanceof Expr\CallLike));
    }
}
