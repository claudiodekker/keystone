<?php

namespace ClaudioDekker\Keystone\Http\PageValues;

/**
 * @api
 */
readonly class RecoveryCodeRegenerationPage
{
    /**
     * Create a new recovery code regeneration page instance.
     *
     * @param  list<string>  $codes  the staged set, shown until it is saved, cancelled or its slot ends
     * @param  bool  $replaces  whether the account holds unused codes that saving would replace, signing out its other sessions
     * @param  ?string  $status  the flashed status label, set when a set expired and this one replaced it
     */
    public function __construct(
        #[\SensitiveParameter] public array $codes,
        public bool $replaces,
        public ?string $status,
    ) {
        //
    }
}
