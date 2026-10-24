<?php

namespace ClaudioDekker\Keystone\Http;

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
     * The number of characters a user agent is cut to.
     */
    protected const int USER_AGENT_LENGTH = 512;

    /**
     * Create the context.
     */
    public function __construct(
        public ?string $ipAddress,
        public ?string $subnet,
        public ?string $userAgent,
        public string $path,
        public string $requestId,
    ) {
        //
    }

    /**
     * Capture the context of the request, minting its request id.
     */
    public static function capture(Request $request): static
    {
        $ipAddress = $request->ip();

        return new static(
            ipAddress: $ipAddress,
            subnet: $ipAddress === null ? null : Subnet::of($ipAddress),
            userAgent: static::clean($request->userAgent()),
            path: $request->path(),
            requestId: strtolower((string) Str::ulid()),
        );
    }

    /**
     * Get the context captured for the request, if any.
     */
    public static function of(Request $request): ?static
    {
        $context = $request->attributes->get(static::class);

        return $context instanceof static ? $context : null;
    }

    /**
     * Keep the context on the request.
     */
    public function storeOn(Request $request): void
    {
        $request->attributes->set(static::class, $this);
    }

    /**
     * Clean the user agent of invalid UTF-8 and control characters, and cut it to a fixed length.
     */
    protected static function clean(?string $userAgent): ?string
    {
        if ($userAgent === null) {
            return null;
        }

        $valid = mb_scrub($userAgent, 'UTF-8');
        $printable = preg_replace('/\p{Cc}/u', ' ', $valid) ?? '';

        return mb_substr($printable, 0, static::USER_AGENT_LENGTH);
    }
}
