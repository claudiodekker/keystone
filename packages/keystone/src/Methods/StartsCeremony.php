<?php

namespace ClaudioDekker\Keystone\Methods;

/**
 * @internal
 */
interface StartsCeremony
{
    /**
     * Start the type's ceremony on the surface, and get what core keeps for its verify until the ceremony ends, or null when there is nothing to keep.
     *
     * Keep it small: anything a page can draw from it, such as a QR code, is drawn again on each request.
     *
     * @return array<string, mixed>|null
     */
    public function start(Surface $surface): ?array;
}
