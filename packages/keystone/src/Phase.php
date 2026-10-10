<?php

namespace ClaudioDekker\Keystone;

use Carbon\CarbonImmutable;

/**
 * @internal
 */
interface Phase
{
    /**
     * Get the time the phase counts from.
     */
    public function startsAt(): CarbonImmutable;

    /**
     * Get the time the phase ends.
     */
    public function endsAt(): CarbonImmutable;

    /**
     * Convert the phase to the values the session holds for it, tagged with its kind.
     *
     * @return array<string, mixed>
     */
    public function toSession(): array;
}
