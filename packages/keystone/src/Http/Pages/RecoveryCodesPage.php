<?php

namespace ClaudioDekker\Keystone\Http\Pages;

/**
 * @api
 */
readonly class RecoveryCodesPage
{
    /**
     * Create the page value.
     *
     * @param  list<string>  $codes  the staged codes, as they are shown
     */
    public function __construct(
        public array $codes,
    ) {
        //
    }
}
