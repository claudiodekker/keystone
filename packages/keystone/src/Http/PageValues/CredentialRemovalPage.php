<?php

namespace ClaudioDekker\Keystone\Http\PageValues;

/**
 * @api
 */
readonly class CredentialRemovalPage
{
    /**
     * Create a new credential removal page value.
     */
    public function __construct(
        public int $id,
        public string $type,
        public ?string $label,
        public bool $listed,
    ) {
        //
    }
}
