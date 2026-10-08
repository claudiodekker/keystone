<?php

namespace ClaudioDekker\Keystone;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

/**
 * @internal
 *
 * @phpstan-consistent-constructor
 */
readonly class RequestContext
{
    /**
     * The request attribute that holds the context captured for the request.
     */
    protected const string ATTRIBUTE = 'keystone.request_context';

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
     * Get the context captured for the request, capturing it when none was.
     */
    public static function of(SymfonyRequest $request): static
    {
        $context = $request->attributes->get(static::ATTRIBUTE);

        return $context instanceof static ? $context : static::capture($request);
    }

    /**
     * Capture the context of the request, giving it a new request id, and keep it on the request.
     */
    public static function capture(SymfonyRequest $request): static
    {
        $illuminate = $request instanceof Request ? $request : Request::createFromBase($request);

        $context = new static(
            ipAddress: $illuminate->ip(),
            userAgent: $illuminate->userAgent(),
            path: $illuminate->path(),
            requestId: (string) Str::ulid(),
        );

        $request->attributes->set(static::ATTRIBUTE, $context);

        return $context;
    }

    /**
     * Get the subnet the request comes from, or null when its IP address can't be parsed.
     */
    public function subnet(): ?Subnet
    {
        return Subnet::of($this->ipAddress);
    }
}
