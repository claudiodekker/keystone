<?php

namespace ClaudioDekker\Keystone\Lint\Checks;

use ClaudioDekker\Keystone\Lint\Check;
use ClaudioDekker\Keystone\Lint\File;
use PhpParser\Node;
use PhpParser\Node\Expr;

/**
 * Refuse a call on a new object when both the constructor and the call take several arguments.
 */
class NoNewThenCall extends Check
{
    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Assign the new object to a variable, then call the method on it.';
    }

    /**
     * Get the lines of the calls on a new object.
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        return $file->lines($file->find(fn (Node $node) => $this->isNewThenCall($node)));
    }

    /**
     * Determine if the node is a call on a new object, with several arguments on both.
     */
    protected function isNewThenCall(Node $node): bool
    {
        return ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall)
            && $node->var instanceof Expr\New_
            && count($node->var->args) > 1
            && count($node->args) > 1;
    }
}
