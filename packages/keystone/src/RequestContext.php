<?php

namespace ClaudioDekker\Keystone;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * @internal
 *
 * @phpstan-consistent-constructor
 */
readonly class RequestContext
{
    /**
     * The most characters of a user agent kept, everywhere one is stored.
     */
    protected const int USER_AGENT_LENGTH = 512;

    /**
     * The user agent, cleaned and cut to the length kept everywhere.
     */
    public ?string $userAgent;

    /**
     * Create a new request context instance.
     */
    public function __construct(
        public ?string $ipAddress = null,
        ?string $userAgent = null,
        public ?string $path = null,
        public ?string $requestId = null,
        public ?CarbonImmutable $occurredAt = null,
    ) {
        $this->userAgent = static::clean($userAgent, static::USER_AGENT_LENGTH);
    }

    /**
     * Capture the context of the request, giving it a new request id.
     */
    public static function capture(Request $request): static
    {
        return new static(
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            path: $request->path(),
            requestId: (string) Str::ulid(),
        );
    }

    /**
     * Get the subnet the request comes from, or null when its IP address can't be parsed.
     */
    public function subnet(): ?Subnet
    {
        return Subnet::of($this->ipAddress);
    }

    /**
     * Cut a value taken from input to the length, with invalid UTF-8 made valid and control characters and line and paragraph separators replaced by spaces.
     */
    public static function clean(?string $value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }

        $printable = (string) preg_replace('/[\p{Cc}\p{Zl}\p{Zp}]/u', ' ', mb_scrub($value, 'UTF-8'));

        return Str::substr($printable, 0, $length);
    }
}
