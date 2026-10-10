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
     * @param  string  $origin  what opened the sign-in the codes are owed for
     */
    public function __construct(
        #[\SensitiveParameter] public array $codes,
        public string $origin,
    ) {
        //
    }
}
