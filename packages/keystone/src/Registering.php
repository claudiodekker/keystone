<?php

namespace ClaudioDekker\Keystone;

use Carbon\CarbonImmutable;

/**
 * @internal
 *
 * @phpstan-consistent-constructor
 */
readonly class Registering implements Phase
{
    /**
     * The tag the session holds a registration under.
     */
    public const string TAG = 'registering';

    /**
     * How long an address waits for its registration to finish.
     */
    public const int WINDOW_SECONDS = 1800;

    /**
     * Create a new registering instance.
     */
    public function __construct(
        public string $address,
        public CarbonImmutable $startedAt,
        public bool $verified,
    ) {
        //
    }

    /**
     * Read the registration the session holds, or null when any field is missing or of the wrong type.
     *
     * @param  array<mixed>  $values
     */
    public static function fromSession(array $values): ?static
    {
        if (! is_string($values['address'] ?? null) || ! is_int($values['started_at'] ?? null) || ! is_bool($values['verified'] ?? null)) {
            return null;
        }

        return new static(
            address: $values['address'],
            startedAt: CarbonImmutable::createFromTimestamp($values['started_at']),
            verified: $values['verified'],
        );
    }

    /**
     * Get the time the registration started.
     */
    public function startsAt(): CarbonImmutable
    {
        return $this->startedAt;
    }

    /**
     * Get the time the registration's window ends.
     */
    public function endsAt(): CarbonImmutable
    {
        return $this->startedAt->addSeconds(self::WINDOW_SECONDS);
    }

    /**
     * Convert the registration to the values the session holds for it, tagged with its kind.
     *
     * @return array<string, mixed>
     */
    public function toSession(): array
    {
        return [
            'phase' => self::TAG,
            'address' => $this->address,
            'started_at' => $this->startedAt->getTimestamp(),
            'verified' => $this->verified,
        ];
    }
}
