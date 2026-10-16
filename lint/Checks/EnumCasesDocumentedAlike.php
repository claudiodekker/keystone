<?php

namespace ClaudioDekker\Keystone\Lint\Checks;

use ClaudioDekker\Keystone\Lint\EnumCases;
use PhpParser\Node\Stmt;

/**
 * Require an enum to document every case or none.
 */
class EnumCasesDocumentedAlike extends EnumCases
{
    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Document every case of the enum, or none.';
    }

    /**
     * Get the undocumented cases of an enum that documents some of its cases.
     *
     * @param  list<Stmt\EnumCase>  $cases
     * @return list<Stmt\EnumCase>
     */
    protected function offending(array $cases): array
    {
        $undocumented = collect($cases)->filter(fn (Stmt\EnumCase $case) => $case->getDocComment() === null);

        if ($undocumented->count() === count($cases)) {
            return [];
        }

        return $undocumented->values()->all();
    }
}
