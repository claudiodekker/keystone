<?php

namespace ClaudioDekker\Keystone\Session;

/**
 * @internal
 *
 * @phpstan-consistent-constructor
 */
readonly class SudoGrant
{
    /**
     * The kind the grant's session value names, which tells it apart from the session's other phases.
     */
    protected const string KIND = 'sudo_grant';

    /**
     * Create the grant.
     *
     * @param  int  $grantedAt  the Unix second it was granted
     * @param  string  $subnet  the subnet of the address it was granted to, in CIDR notation
     */
    public function __construct(
        public int $grantedAt,
        public string $subnet,
    ) {
        //
    }

    /**
     * Read the grant from its session value, or null when the value holds none.
     *
     * @param  array<array-key, mixed>  $value
     */
    public static function fromSession(array $value): ?static
    {
        $value += array_fill_keys(['kind', 'granted_at', 'subnet'], null);

        if ($value['kind'] !== static::KIND
            || ! is_int($value['granted_at'])
            || ! is_string($value['subnet'])
        ) {
            return null;
        }

        return new static(
            grantedAt: $value['granted_at'],
            subnet: $value['subnet'],
        );
    }

    /**
     * Get the grant as its session value.
     *
     * @return array{kind: string, granted_at: int, subnet: string}
     */
    public function toSession(): array
    {
        return [
            'kind' => static::KIND,
            'granted_at' => $this->grantedAt,
            'subnet' => $this->subnet,
        ];
    }

    /**
     * Determine if the grant is live at the Unix second for the lifetime: granted no later than then, and less than the lifetime before.
     */
    public function isLiveAt(int $now, int $lifetimeSeconds): bool
    {
        return $this->grantedAt <= $now && $now < $this->grantedAt + $lifetimeSeconds;
    }
}
