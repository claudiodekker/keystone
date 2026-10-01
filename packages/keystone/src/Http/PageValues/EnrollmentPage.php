<?php

namespace ClaudioDekker\Keystone\Http\PageValues;

/**
 * @api
 */
readonly class EnrollmentPage
{
    /**
     * Create a new enrollment page value.
     *
     * @param  list<array{type: string, shape: string}>  $types
     */
    public function __construct(
        public array $types,
        public ?string $preselect,
        public string $origin,
    ) {
        //
    }
}
