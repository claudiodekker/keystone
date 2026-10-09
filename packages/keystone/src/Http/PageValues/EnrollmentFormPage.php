<?php

namespace ClaudioDekker\Keystone\Http\PageValues;

/**
 * @api
 */
readonly class EnrollmentFormPage
{
    /**
     * Create a new enrollment form page value.
     *
     * @param  array<string, string>  $ceremony
     * @param  list<array{id: int, label: ?string, removable: bool}>  $held  the account's usable credentials of the type, and whether each can be removed
     */
    public function __construct(
        public string $type,
        public string $shape,
        public array $ceremony,
        public ?string $status,
        public array $held,
    ) {
        //
    }
}
