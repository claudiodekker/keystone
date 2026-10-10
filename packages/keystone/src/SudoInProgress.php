<?php

namespace ClaudioDekker\Keystone;

use Carbon\CarbonImmutable;
use ClaudioDekker\Keystone\Methods\Surface;

/**
 * @internal
 *
 * @phpstan-consistent-constructor
 */
readonly class SudoInProgress implements Phase
{
    /**
     * The tag the session holds a sudo-in-progress under.
     */
    public const string TAG = 'sudo_in_progress';

    /**
     * How long a sudo-in-progress lasts from the refusal that started it.
     */
    public const int LIFETIME_SECONDS = 900;

    /**
     * Create a new sudo-in-progress instance.
     *
     * @param  string|null  $firstFactor  the type that passed the first step, or null while that step is still owed
     */
    public function __construct(
        public string $intendedUrl,
        public CarbonImmutable $startedAt,
        public ?string $firstFactor,
    ) {
        //
    }

    /**
     * Read the sudo-in-progress the session holds, or null when a field it needs is missing or of the wrong type.
     *
     * @param  array<mixed>  $values
     */
    public static function fromSession(array $values): ?static
    {
        $firstFactor = $values['first_factor'] ?? null;

        if (! is_int($values['started_at'] ?? null) || ! is_string($values['intended_url'] ?? null)) {
            return null;
        }

        return new static(
            intendedUrl: $values['intended_url'],
            startedAt: CarbonImmutable::createFromTimestamp($values['started_at']),
            firstFactor: is_string($firstFactor) ? $firstFactor : null,
        );
    }

    /**
     * Get the time the refusal started it.
     */
    public function startsAt(): CarbonImmutable
    {
        return $this->startedAt;
    }

    /**
     * Get the time it ends.
     */
    public function endsAt(): CarbonImmutable
    {
        return $this->startedAt->addSeconds(self::LIFETIME_SECONDS);
    }

    /**
     * Get the surface the step it is at verifies on: sign-in until a first factor passed, the challenge after.
     */
    public function surface(): Surface
    {
        return $this->firstFactor === null ? Surface::SIGN_IN : Surface::CHALLENGE;
    }

    /**
     * Convert the sudo-in-progress to the values the session holds for it, tagged with its kind.
     *
     * @return array<string, mixed>
     */
    public function toSession(): array
    {
        return [
            'phase' => self::TAG,
            'started_at' => $this->startedAt->getTimestamp(),
            'intended_url' => $this->intendedUrl,
            'first_factor' => $this->firstFactor,
        ];
    }
}
