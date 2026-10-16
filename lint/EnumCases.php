<?php

namespace ClaudioDekker\Keystone\Lint;

use PhpParser\Node;
use PhpParser\Node\Stmt;

/**
 * Check the cases of every enum.
 */
abstract class EnumCases extends Check
{
    /**
     * Get the cases of the enum that break the rule.
     *
     * @param  list<Stmt\EnumCase>  $cases
     * @return list<Stmt\EnumCase>
     */
    abstract protected function offending(array $cases): array;

    /**
     * Get the lines of the enum cases that break the rule.
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        return collect($file->find(fn (Node $node) => $node instanceof Stmt\Enum_))
            ->flatMap(fn (Stmt\Enum_ $enum) => $this->offending($this->cases($enum)))
            ->map(fn (Stmt\EnumCase $case) => $case->getStartLine())
            ->values()
            ->all();
    }

    /**
     * Get the cases of the enum.
     *
     * @return list<Stmt\EnumCase>
     */
    protected function cases(Stmt\Enum_ $enum): array
    {
        return collect($enum->stmts)
            ->filter(fn (Node $statement) => $statement instanceof Stmt\EnumCase)
            ->values()
            ->all();
    }
}
