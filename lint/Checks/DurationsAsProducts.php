<?php

namespace ClaudioDekker\Keystone\Lint\Checks;

use ClaudioDekker\Keystone\Lint\Check;
use ClaudioDekker\Keystone\Lint\File;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;

/**
 * Require a duration in seconds in a shipped config file as a product of its units.
 */
class DurationsAsProducts extends Check
{
    /**
     * The paths the check reads, as fnmatch patterns from the repository root.
     *
     * @var list<string>
     */
    protected array $paths = ['packages/*/config/*.php'];

    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Write the duration as a product of its units, such as 12 * 60 * 60.';
    }

    /**
     * Get the lines of the durations written as a total.
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        return $file->lines($file->find(fn (Node $node) => $this->isTotalOfMinutes($node)));
    }

    /**
     * Determine if the node is a "_seconds" item whose value is more than a minute and a whole number of minutes.
     */
    protected function isTotalOfMinutes(Node $node): bool
    {
        return $node instanceof Expr\ArrayItem
            && $node->key instanceof Scalar\String_
            && str_ends_with($node->key->value, '_seconds')
            && $node->value instanceof Scalar\Int_
            && $node->value->value > 60
            && $node->value->value % 60 === 0;
    }
}
