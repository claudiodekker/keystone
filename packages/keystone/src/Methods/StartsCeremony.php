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

    /**
     * Get what the page shows of the ceremony on the surface for the account, drawn from what start() kept.
     *
     * @param  array<string, mixed>  $ceremony
     * @param  string  $account  the name the account signs in with, such as an authenticator app's label
     * @return array<string, mixed>
     */
    public function page(Surface $surface, array $ceremony, string $account): array;
}
