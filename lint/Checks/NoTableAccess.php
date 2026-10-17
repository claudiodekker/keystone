<?php

namespace ClaudioDekker\Keystone\Lint\Checks;

use ClaudioDekker\Keystone\Lint\Check;
use ClaudioDekker\Keystone\Lint\File;
use PhpParser\Node;
use PhpParser\Node\Expr;

/**
 * Refuse table and connection access, so storage goes through models.
 */
class NoTableAccess extends Check
{
    /**
     * The paths the check reads, as fnmatch patterns from the repository root.
     *
     * @var list<string>
     */
    protected array $paths = ['packages/*/src/*.php', 'packages/*/app-tests/*.php', 'packages/*/database/*.php', 'packages/*/tests/*.php'];

    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Go through the model; apps may rename the table.';
    }

    /**
     * Get the lines that call table() or getConnection().
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        return $file->lines($file->find(fn (Node $node) => $this->isTableAccess($node)));
    }

    /**
     * Determine if the node calls getConnection(), or table() on the query builder or the DB facade.
     */
    protected function isTableAccess(Node $node): bool
    {
        $name = $this->memberName($node);

        if ($name === 'getconnection') {
            return true;
        }

        if ($name !== 'table') {
            return false;
        }

        return ! $node instanceof Expr\StaticCall || $this->isDatabaseFacade($node->class);
    }

    /**
     * Get the lowercase name of the method a call node invokes, if it is written out.
     */
    protected function memberName(Node $node): ?string
    {
        $calls = [Expr\MethodCall::class, Expr\NullsafeMethodCall::class, Expr\StaticCall::class];

        if (! in_array($node::class, $calls, true) || ! $node->name instanceof Node\Identifier) {
            return null;
        }

        return $node->name->toLowerString();
    }

    /**
     * Determine if the class of a static call is the DB facade, under whatever name the file imports it.
     */
    protected function isDatabaseFacade(Node $class): bool
    {
        return $class instanceof Node\Name
            && in_array($class->toString(), ['DB', 'Illuminate\\Support\\Facades\\DB'], true);
    }
}
