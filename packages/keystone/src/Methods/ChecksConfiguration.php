<?php

namespace ClaudioDekker\Keystone\Methods;

/**
 * @internal
 */
interface ChecksConfiguration
{
    /**
     * Get the failure of each problem with the type's config, which the boot checks refuse to boot for.
     *
     * @return list<string>
     */
    public function configurationFailures(): array;
}
