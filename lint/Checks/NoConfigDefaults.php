<?php

namespace ClaudioDekker\Keystone\Lint\Checks;

use ClaudioDekker\Keystone\Lint\Check;
use ClaudioDekker\Keystone\Lint\File;
use PhpParser\Node;
use PhpParser\Node\Expr;

/**
 * Refuse a config read with an inline default.
 */
class NoConfigDefaults extends Check
{
    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Remove the inline default; the config file holds it.';
    }

    /**
     * Get the lines that read a config value with an inline default.
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        return $file->lines($file->find(fn (Node $node) => $this->readsWithDefault($node)));
    }

    /**
     * Determine if the node reads a config value and passes a default.
     */
    protected function readsWithDefault(Node $node): bool
    {
        if ($node instanceof Expr\FuncCall) {
            return $this->isConfigFunction($node) && count($node->args) > 1;
        }

        if ($node instanceof Expr\MethodCall) {
            return $node->var instanceof Expr\FuncCall
                && $this->isConfigFunction($node->var)
                && $node->var->args === []
                && $this->isRead($node);
        }

        return $node instanceof Expr\StaticCall
            && $node->class instanceof Node\Name
            && in_array($node->class->toString(), ['Config', 'Illuminate\\Support\\Facades\\Config'], true)
            && $this->isRead($node);
    }

    /**
     * Determine if the node calls the config() function.
     */
    protected function isConfigFunction(Expr\FuncCall $call): bool
    {
        return $call->name instanceof Node\Name && $call->name->toLowerString() === 'config';
    }

    /**
     * Determine if the call reads a value and passes a default.
     */
    protected function isRead(Expr\MethodCall|Expr\StaticCall $call): bool
    {
        return $call->name instanceof Node\Identifier
            && in_array($call->name->toLowerString(), ['array', 'boolean', 'float', 'get', 'integer', 'string'], true)
            && count($call->args) > 1;
    }
}
