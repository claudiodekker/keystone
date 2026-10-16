<?php

namespace ClaudioDekker\Keystone\Lint\Checks;

use ClaudioDekker\Keystone\Lint\FunctionCalls;
use PhpParser\Node;
use PhpParser\Node\Expr;

/**
 * Refuse a call that prints a value and stops, or leaves the value for a debugger.
 */
class NoDebugCalls extends FunctionCalls
{
    /**
     * The refused functions, lowercase.
     *
     * @var list<string>
     */
    protected array $functions = ['dd', 'dump', 'ray', 'var_dump'];

    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Remove the debug call.';
    }

    /**
     * Determine if the node calls a refused function or method by name.
     */
    protected function isRefusedCall(Node $node): bool
    {
        if ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) {
            return $node->name instanceof Node\Identifier
                && in_array($node->name->toLowerString(), $this->functions, true);
        }

        return parent::isRefusedCall($node);
    }
}
