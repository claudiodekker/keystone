<?php

namespace ClaudioDekker\Keystone;

use Carbon\CarbonImmutable;

/**
 * @internal
 *
 * @template-covariant TLink of EmailedLink
 */
readonly class VerifiedLink
{
    /**
     * Create a new verified link instance.
     *
     * @param  TLink  $link
     */
    public function __construct(
        public EmailedLink $link,
        public string $signature,
        public CarbonImmutable $expiresAt,
    ) {
        //
    }
}
