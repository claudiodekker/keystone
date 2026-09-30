<?php

namespace ClaudioDekker\Keystone\Exceptions;

use RuntimeException;

/**
 * @api
 */
class Misconfigured extends RuntimeException
{
    /**
     * Create a new misconfigured exception instance.
     *
     * @param  non-empty-list<string>  $failures
     */
    public function __construct(
        public readonly array $failures,
    ) {
        $lines = array_map(fn (string $failure) => "- {$failure}", $failures);

        parent::__construct("Keystone refused to boot:\n".implode("\n", $lines));
    }
}
