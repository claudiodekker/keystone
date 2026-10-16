<?php

namespace ClaudioDekker\Keystone\Lint\Checks;

use ClaudioDekker\Keystone\Lint\FunctionCalls;

/**
 * Refuse env() outside the config files.
 */
class NoEnvOutsideConfig extends FunctionCalls
{
    /**
     * The paths the check skips.
     *
     * @var list<string>
     */
    protected array $except = ['packages/*/config/*'];

    /**
     * The refused functions, lowercase.
     *
     * @var list<string>
     */
    protected array $functions = ['env'];

    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Read the value through config(); only config files call env().';
    }
}
