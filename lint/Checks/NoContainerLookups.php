<?php

namespace ClaudioDekker\Keystone\Lint\Checks;

use ClaudioDekker\LaravelGuardrails\ForbiddenCalls;
use ClaudioDekker\LaravelGuardrails\Kind;

class NoContainerLookups extends ForbiddenCalls
{
    /**
     * The functions the rule forbids.
     *
     * @var list<string>
     */
    protected array $functions = ['app', 'resolve'];

    /**
     * Get the kinds of code the check reads.
     *
     * @return list<Kind>
     */
    public function kinds(): array
    {
        return [Kind::SOURCE];
    }

    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Inject the service through the constructor instead.';
    }
}
