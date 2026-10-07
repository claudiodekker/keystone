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
     * Create a new request context instance.
     */
    public function __construct(
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
        public ?string $path = null,
        public ?string $requestId = null,
        public ?CarbonImmutable $occurredAt = null,
    ) {
        //
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
}
