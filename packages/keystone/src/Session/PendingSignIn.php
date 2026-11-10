<?php

namespace ClaudioDekker\Keystone\Session;

/**
 * @internal
 *
 * @phpstan-consistent-constructor
 */
readonly class PendingSignIn
{
    /**
     * How long a pending sign-in lasts after its hold, in seconds.
     */
    protected const int LIFETIME_SECONDS = 15 * 60;

    /**
     * Create the pending sign-in.
     *
     * @param  int|string  $account  the id of the account it holds
     * @param  string  $firstFactor  the name of the credential type that passed the first factor
     * @param  int|null  $epoch  the account's credential epoch at the hold
     * @param  int  $heldAt  the Unix second of the hold
     * @param  string|null  $secondFactor  the name of the credential type that answered the challenge or was enrolled, null until one did
     */
    public function __construct(
        public int|string $account,
        public string $firstFactor,
        public Stage $stage,
        public string $intendedPath,
        public ?int $epoch,
        public int $heldAt,
        public ?string $secondFactor = null,
    ) {
        //
    }

    /**
     * Read the pending sign-in from its session value, or null when the value holds none.
     *
     * @param  array<array-key, mixed>  $value
     */
    public static function fromSession(array $value): ?static
    {
        $value += array_fill_keys(['account', 'first_factor', 'stage', 'intended_path', 'epoch', 'held_at', 'second_factor'], null);

        $stage = is_string($value['stage']) ? Stage::tryFrom($value['stage']) : null;

        if (! is_int($value['account']) && ! is_string($value['account'])) {
            return null;
        }

        if (! is_int($value['epoch']) && $value['epoch'] !== null) {
            return null;
        }

        if (! is_string($value['second_factor']) && $value['second_factor'] !== null) {
            return null;
        }

        if ($stage === null
            || ! is_string($value['first_factor'])
            || ! is_string($value['intended_path'])
            || ! is_int($value['held_at'])
        ) {
            return null;
        }

        return new static(
            account: $value['account'],
            firstFactor: $value['first_factor'],
            stage: $stage,
            intendedPath: $value['intended_path'],
            epoch: $value['epoch'],
            heldAt: $value['held_at'],
            secondFactor: $value['second_factor'],
        );
    }

    /**
     * Get the pending sign-in as its session value.
     *
     * @return array{account: int|string, first_factor: string, stage: string, intended_path: string, epoch: int|null, held_at: int, second_factor: string|null}
     */
    public function toSession(): array
    {
        return [
            'account' => $this->account,
            'first_factor' => $this->firstFactor,
            'stage' => $this->stage->value,
            'intended_path' => $this->intendedPath,
            'epoch' => $this->epoch,
            'held_at' => $this->heldAt,
            'second_factor' => $this->secondFactor,
        ];
    }

    /**
     * Get the pending sign-in held at enrollment once the named type answered its challenge or was enrolled, keeping its lifetime.
     */
    public function passedBy(string $secondFactor): static
    {
        return new static(
            account: $this->account,
            firstFactor: $this->firstFactor,
            stage: Stage::ENROLLMENT,
            intendedPath: $this->intendedPath,
            epoch: $this->epoch,
            heldAt: $this->heldAt,
            secondFactor: $secondFactor,
        );
    }

    /**
     * Determine if the pending sign-in has outlived its fixed lifetime at the Unix second.
     */
    public function hasExpiredAt(int $now): bool
    {
        return $now >= $this->endsAt();
    }

    /**
     * Get the Unix second the pending sign-in ends at.
     */
    public function endsAt(): int
    {
        return $this->heldAt + static::LIFETIME_SECONDS;
    }
}
