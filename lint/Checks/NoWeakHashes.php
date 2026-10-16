<?php

namespace ClaudioDekker\Keystone\Lint\Checks;

use ClaudioDekker\Keystone\Lint\FunctionCalls;

/**
 * Refuse md5() and sha1() in src.
 */
class NoWeakHashes extends FunctionCalls
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
    protected array $functions = ['md5', 'sha1'];

    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return "Use hash('sha256', ...) or hash_hmac() instead.";
    }
}
