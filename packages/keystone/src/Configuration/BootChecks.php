<?php

namespace ClaudioDekker\Keystone\Configuration;

use ClaudioDekker\Keystone\Exceptions\KeystoneMisconfiguredException;

/**
 * @internal
 */
class BootChecks
{
    /**
     * Create the checks.
     */
    public function __construct(
        protected KeyChecks $keys,
        protected MethodChecks $methods,
        protected ModelChecks $models,
    ) {
        //
    }

    /**
     * Run every check, and throw once with all the failures.
     *
     * @throws KeystoneMisconfiguredException
     */
    public function run(): void
    {
        $failures = $this->failures();

        if ($failures === []) {
            return;
        }

        throw KeystoneMisconfiguredException::bootFailures($failures);
    }

    /**
     * Get the failure of every check that fails.
     *
     * @return list<string>
     */
    protected function failures(): array
    {
        return [
            ...$this->keys->failures(),
            ...$this->methods->failures(),
            ...$this->models->failures(),
        ];
    }
}
