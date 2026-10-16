<?php

namespace ClaudioDekker\Keystone\Lint\Checks;

use ClaudioDekker\Keystone\Lint\FunctionCalls;

/**
 * Refuse rand(), mt_rand() and uniqid() in src.
 */
class NoWeakRandomness extends FunctionCalls
{
    /**
     * The paths the check reads, as fnmatch patterns from the repository root.
     *
     * @var list<string>
     */
    protected array $paths = ['packages/*/src/*.php'];

    /**
     * The refused functions, lowercase.
     *
     * @var list<string>
     */
    protected array $functions = ['mt_rand', 'rand', 'uniqid'];

    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Use random_int() or Str::random() instead.';
    }
}
