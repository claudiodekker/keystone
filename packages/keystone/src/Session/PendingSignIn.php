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
     */
    public function __construct(
        public int|string $account,
        public string $firstFactor,
        public Stage $stage,
        public string $intendedPath,
        public ?int $epoch,
        public int $heldAt,
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
        $value += array_fill_keys(['account', 'first_factor', 'stage', 'intended_path', 'epoch', 'held_at'], null);

        $stage = is_string($value['stage']) ? Stage::tryFrom($value['stage']) : null;

        if (! is_int($value['account']) && ! is_string($value['account'])) {
            return null;
        }

        if (! is_int($value['epoch']) && $value['epoch'] !== null) {
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
        );
    }

    /**
     * Get the pending sign-in as its session value.
     *
     * @return array{account: int|string, first_factor: string, stage: string, intended_path: string, epoch: int|null, held_at: int}
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
        ];
    }

    /**
     * Determine if the pending sign-in has outlived its fixed lifetime at the Unix second.
     */
    public function hasExpiredAt(int $now): bool
    {
        return $now >= $this->heldAt + static::LIFETIME_SECONDS;
    }
}
