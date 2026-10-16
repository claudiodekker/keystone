<?php

namespace ClaudioDekker\Keystone\Lint\Checks;

use ClaudioDekker\Keystone\Lint\Check;
use ClaudioDekker\Keystone\Lint\File;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;

/**
 * Refuse a second class, interface, trait or enum in a file.
 */
class OneClassPerFile extends Check
{
    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Move the class into its own file, named after it.';
    }

    /**
     * Get the lines that declare a named type after the first in the file.
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        $declarations = $file->find(fn (Node $node) => $node instanceof ClassLike && $node->name !== null);

        return $file->lines(array_slice($declarations, 1));
    }
}
