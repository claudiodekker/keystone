<?php

namespace ClaudioDekker\Keystone;

use Carbon\CarbonImmutable;

/**
 * @internal
 *
 * @phpstan-consistent-constructor
 */
readonly class SudoGrant implements Phase
{
    /**
     * The tag the session holds a sudo grant under.
     */
    public const string TAG = 'sudo_granted';

    /**
     * Create a new sudo grant instance.
     */
    public function __construct(
        public CarbonImmutable $grantedAt,
        public int $lifetimeSeconds,
        public Subnet $subnet,
    ) {
        //
    }

    /**
     * Read the sudo grant the session holds, lasting the lifetime, or null when any field is missing or of the wrong type.
     *
     * @param  array<mixed>  $values
     */
    public static function fromSession(array $values, int $lifetimeSeconds): ?static
    {
        if (! is_int($values['granted_at'] ?? null) || ! is_string($values['subnet'] ?? null)) {
            return null;
        }

        return new static(
            grantedAt: CarbonImmutable::createFromTimestamp($values['granted_at']),
            lifetimeSeconds: $lifetimeSeconds,
            subnet: new Subnet($values['subnet']),
        );
    }

    /**
     * Get the time sudo was granted.
     */
    public function startsAt(): CarbonImmutable
    {
        return $this->grantedAt;
    }

    /**
     * Get the time the grant ends.
     */
    public function endsAt(): CarbonImmutable
    {
        return $this->grantedAt->addSeconds($this->lifetimeSeconds);
    }

    /**
     * Convert the grant to the values the session holds for it, tagged with its kind.
     *
     * @return array<string, mixed>
     */
    public function toSession(): array
    {
        return [
            'phase' => self::TAG,
            'granted_at' => $this->grantedAt->getTimestamp(),
            'subnet' => $this->subnet->cidr,
        ];
    }
}
