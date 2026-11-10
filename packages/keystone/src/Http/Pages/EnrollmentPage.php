<?php

namespace ClaudioDekker\Keystone\Http\Pages;

/**
 * @api
 */
readonly class EnrollmentPage
{
    /**
     * Create the page value.
     *
     * @param  array<int, array{name: string, shape: string, ceremony: array<string, mixed>|null}>  $types  the second-factor types the account can enroll, each with what its started ceremony shows
     * @param  string|null  $preselected  the name of the type the page opens on
     */
    public function __construct(
        public array $types,
        public ?string $preselected,
    ) {
        //
    }
}
