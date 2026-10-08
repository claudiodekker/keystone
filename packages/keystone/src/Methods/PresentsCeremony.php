<?php

namespace ClaudioDekker\Keystone\Methods;

/**
 * @internal
 */
interface PresentsCeremony
{
    /**
     * Get what the type's enrollment form shows: the page its ceremony keeps, with what the type draws from it on every request, such as a QR code.
     *
     * What the ceremony keeps lives in the session, where a cookie session leaves little room. Anything large that the page can be drawn from again belongs here.
     *
     * @param  array<string, string>  $page
     * @return array<string, string>
     */
    public function present(array $page): array;
}
