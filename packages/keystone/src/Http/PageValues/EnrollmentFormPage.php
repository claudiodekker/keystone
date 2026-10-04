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
     */
    public function __construct(
        public string $type,
        public string $shape,
        public array $ceremony,
        public ?string $status,
    ) {
        //
    }
}
