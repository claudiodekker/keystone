<?php

namespace ClaudioDekker\Keystone\Configuration;

use ClaudioDekker\Keystone\Exceptions\KeystoneMisconfiguredException;
use Illuminate\Contracts\Foundation\Application;

/**
 * Refuse to boot an app whose setup Keystone can't honour, naming every problem in one exception.
 *
 * @internal
 */
class BootChecks
{
    /**
     * Create the checks.
     */
    public function __construct(
        protected Application $app,
        protected KeyChecks $keys,
        protected MethodChecks $methods,
        protected ModelChecks $models,
        protected ServiceChecks $services,
        protected ProductionChecks $production,
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
        $failures = [
            ...$this->keys->failures(),
            ...$this->methods->failures(),
            ...$this->models->failures(),
            ...$this->services->failures(),
        ];

        if (! $this->app->environment('production')) {
            return $failures;
        }

        return [...$failures, ...$this->production->failures()];
    }
}
