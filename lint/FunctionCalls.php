<?php

namespace ClaudioDekker\Keystone\Lint;

use PhpParser\Node;
use PhpParser\Node\Expr;

/**
 * Refuse calls to a set of global functions.
 */
abstract class FunctionCalls extends Check
{
    /**
     * The refused functions, lowercase.
     *
     * @var list<string>
     */
    protected array $functions = [];

    /**
     * Get the lines that call a refused function.
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        return $file->lines($file->find(fn (Node $node) => $this->isRefusedCall($node)));
    }

    /**
     * Determine if the node calls a refused function by name.
     */
    protected function isRefusedCall(Node $node): bool
    {
        return $node instanceof Expr\FuncCall
            && $node->name instanceof Node\Name
            && in_array($node->name->toLowerString(), $this->functions, true);
    }
}
