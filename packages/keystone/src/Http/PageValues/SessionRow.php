<?php

namespace ClaudioDekker\Keystone\Http\PageValues;

use ClaudioDekker\Keystone\IpLocation;
use ClaudioDekker\Keystone\ListedSession;
use ClaudioDekker\Keystone\SessionInfo;

/**
 * @api
 */
readonly class SessionRow
{
    /**
     * Create a new session row value.
     *
     * @param  string  $handle  names the session in the revoke routes; the session's id never leaves Keystone
     * @param  string  $lastActiveAt  ISO 8601
     * @param  bool  $current  whether it is the session viewing the page, which signs out instead of being revoked
     */
    public function __construct(
        public string $handle,
        public ?string $platform,
        public ?string $browser,
        public ?string $ipAddress,
        public ?string $location,
        public string $lastActiveAt,
        public bool $current,
    ) {
        //
    }

    /**
     * Describe each listed session for the page, locating each IP address once and a failed lookup as unknown.
     *
     * @internal
     *
     * @param  list<ListedSession>  $sessions
     * @return list<self>
     */
    public static function listOf(array $sessions): array
    {
        $locations = [];

        return array_map(function (ListedSession $session) use (&$locations) {
            $device = $session->userAgent === null ? null : app(SessionInfo::class)->describe($session->userAgent);

            if ($session->ipAddress !== null && ! array_key_exists($session->ipAddress, $locations)) {
                $locations[$session->ipAddress] = rescue(fn () => app(IpLocation::class)->locate($session->ipAddress));
            }

            return new self(
                handle: $session->handle,
                platform: $device?->platform,
                browser: $device?->browser,
                ipAddress: $session->ipAddress,
                location: $session->ipAddress === null ? null : $locations[$session->ipAddress],
                lastActiveAt: $session->lastActiveAt->toIso8601String(),
                current: $session->current,
            );
        }, $sessions);
    }
}
