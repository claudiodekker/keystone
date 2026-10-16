<?php

namespace ClaudioDekker\Keystone\Lint\Checks;

use ClaudioDekker\Keystone\Lint\FunctionCalls;

/**
 * Refuse unserialize() in src.
 */
class NoUnserialize extends FunctionCalls
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
    protected array $functions = ['unserialize'];

    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Store JSON and read it with json_decode().';
    }
}
