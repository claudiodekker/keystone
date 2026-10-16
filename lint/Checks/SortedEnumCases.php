<?php

namespace ClaudioDekker\Keystone\Lint\Checks;

use ClaudioDekker\Keystone\Lint\EnumCases;
use Illuminate\Support\Collection;
use PhpParser\Node\Stmt;

/**
 * Require the cases of an enum in alphabetical order.
 */
class SortedEnumCases extends EnumCases
{
    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return "Sort the enum's cases alphabetically.";
    }

    /**
     * Get the cases that sort before the case above them.
     *
     * @param  list<Stmt\EnumCase>  $cases
     * @return list<Stmt\EnumCase>
     */
    protected function offending(array $cases): array
    {
        return collect($cases)
            ->sliding(2)
            ->filter(fn (Collection $pair) => strcmp($pair->last()->name->toString(), $pair->first()->name->toString()) < 0)
            ->map(fn (Collection $pair) => $pair->last())
            ->values()
            ->all();
    }
}
