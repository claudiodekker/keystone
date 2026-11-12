<?php

namespace ClaudioDekker\Keystone\Session;

use ClaudioDekker\Keystone\Methods\Surface;

/**
 * @internal
 *
 * @phpstan-consistent-constructor
 */
readonly class SudoInProgress
{
    /**
     * The kind the sudo-in-progress's session value names, which tells it apart from the session's other phases.
     */
    protected const string KIND = 'sudo';

    /**
     * How long a sudo-in-progress lasts after the refusal that started it, in seconds.
     */
    protected const int LIFETIME_SECONDS = 15 * 60;

    /**
     * Create the sudo-in-progress.
     *
     * @param  string  $intendedPath  the path the user goes on to once granted
     * @param  int|null  $epoch  the account's credential epoch the session was signed in on
     * @param  int  $startedAt  the Unix second of the refusal that started it
     * @param  string|null  $firstFactor  the name of the credential type that passed its first step, null until one did
     */
    public function __construct(
        public string $intendedPath,
        public ?int $epoch,
        public int $startedAt,
        public ?string $firstFactor = null,
    ) {
        //
    }

    /**
     * Read the sudo-in-progress from its session value, or null when the value holds none.
     *
     * @param  array<array-key, mixed>  $value
     */
    public static function fromSession(array $value): ?static
    {
        $value += array_fill_keys(['kind', 'intended_path', 'epoch', 'started_at', 'first_factor'], null);

        if (! is_int($value['epoch']) && $value['epoch'] !== null) {
            return null;
        }

        if (! is_string($value['first_factor']) && $value['first_factor'] !== null) {
            return null;
        }

        if ($value['kind'] !== static::KIND
            || ! is_string($value['intended_path'])
            || ! is_int($value['started_at'])
        ) {
            return null;
        }

        return new static(
            intendedPath: $value['intended_path'],
            epoch: $value['epoch'],
            startedAt: $value['started_at'],
            firstFactor: $value['first_factor'],
        );
    }

    /**
     * Get the sudo-in-progress as its session value.
     *
     * @return array{kind: string, intended_path: string, epoch: int|null, started_at: int, first_factor: string|null}
     */
    public function toSession(): array
    {
        return [
            'kind' => static::KIND,
            'intended_path' => $this->intendedPath,
            'epoch' => $this->epoch,
            'started_at' => $this->startedAt,
            'first_factor' => $this->firstFactor,
        ];
    }

    /**
     * Get the sudo-in-progress going on to the path once granted, keeping its start.
     */
    public function goingTo(string $intendedPath): static
    {
        return new static(
            intendedPath: $intendedPath,
            epoch: $this->epoch,
            startedAt: $this->startedAt,
            firstFactor: $this->firstFactor,
        );
    }

    /**
     * Get the sudo-in-progress at its second step once the named type passed its first, keeping its start.
     */
    public function passedBy(string $firstFactor): static
    {
        return new static(
            intendedPath: $this->intendedPath,
            epoch: $this->epoch,
            startedAt: $this->startedAt,
            firstFactor: $firstFactor,
        );
    }

    /**
     * Get the surface the sudo-in-progress's step verifies on: sign-in's first, the challenge's once a first factor passed.
     */
    public function surface(): Surface
    {
        return $this->firstFactor === null ? Surface::SIGN_IN : Surface::CHALLENGE;
    }

    /**
     * Determine if the sudo-in-progress has outlived its fixed lifetime at the Unix second.
     */
    public function hasExpiredAt(int $now): bool
    {
        return $now >= $this->endsAt();
    }

    /**
     * Get the Unix second the sudo-in-progress ends at.
     */
    public function endsAt(): int
    {
        return $this->startedAt + static::LIFETIME_SECONDS;
    }
}
