<?php

namespace ClaudioDekker\Keystone\Http\PageValues;

/**
 * @api
 */
readonly class RecoveryCodesPage
{
    /**
     * Create a new recovery codes page value.
     *
     * @param  list<string>  $codes
     */
    public function __construct(
        #[\SensitiveParameter] public array $codes,
    ) {
        //
    }
}
