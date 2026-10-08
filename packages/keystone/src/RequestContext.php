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
     * The user agent, cut to the length kept everywhere with control characters replaced by spaces.
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
        $this->userAgent = SecurityEventRecorder::clean($userAgent, SecurityEventRecorder::USER_AGENT_LENGTH);
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
}
