<?php

namespace ClaudioDekker\Keystone;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @internal
 *
 * @phpstan-consistent-constructor
 */
readonly class HeldSignIn implements Phase
{
    /**
     * The tag the session holds a pending sign-in under.
     */
    public const string TAG = 'pending';

    /**
     * Create a new held sign-in instance.
     */
    public function __construct(
        public int|string $accountId,
        public string $firstFactor,
        public PendingOrigin $origin,
        public PendingStage $stage,
        public string $intendedUrl,
        public CarbonImmutable $heldAt,
        public int $epoch,
        public bool $secondFactorPassed,
        public ?int $pendingChallengeId,
        public RememberMe $rememberMe,
    ) {
        //
    }

    /**
     * Read the pending sign-in the session holds, or null when any field is missing or of the wrong type.
     *
     * @param  array<mixed>  $values
     */
    public static function fromSession(array $values): ?static
    {
        $account = $values['account'] ?? null;
        $origin = is_string($values['origin'] ?? null) ? PendingOrigin::tryFrom($values['origin']) : null;
        $stage = is_string($values['stage'] ?? null) ? PendingStage::tryFrom($values['stage']) : null;
        $rememberMe = is_string($values['remember_me'] ?? null) ? RememberMe::tryFrom($values['remember_me']) : null;
        $pendingChallengeId = $values['pending_challenge_id'] ?? null;

        if (! is_int($account) && ! is_string($account)) {
            return null;
        }

        if ($origin === null || $stage === null || $rememberMe === null) {
            return null;
        }

        if (! is_string($values['first_factor'] ?? null) || ! is_string($values['intended_url'] ?? null)) {
            return null;
        }

        if (! is_int($values['held_at'] ?? null) || ! is_int($values['epoch'] ?? null) || ! is_bool($values['second_factor_passed'] ?? null)) {
            return null;
        }

        if (! is_int($pendingChallengeId) && $pendingChallengeId !== null) {
            return null;
        }

        return new static(
            accountId: $account,
            firstFactor: $values['first_factor'],
            origin: $origin,
            stage: $stage,
            intendedUrl: $values['intended_url'],
            heldAt: CarbonImmutable::createFromTimestamp($values['held_at']),
            epoch: $values['epoch'],
            secondFactorPassed: $values['second_factor_passed'],
            pendingChallengeId: $pendingChallengeId,
            rememberMe: $rememberMe,
        );
    }

    /**
     * Get the time the sign-in was held.
     */
    public function startsAt(): CarbonImmutable
    {
        return $this->heldAt;
    }

    /**
     * Get the time the pending sign-in ends.
     */
    public function endsAt(): CarbonImmutable
    {
        return $this->heldAt->addSeconds(PendingSignIn::LIFETIME_SECONDS);
    }

    /**
     * Get the sign-in as it is once it passed its second factor, held on at enrollment for what it still owes.
     */
    public function passSecondFactor(): static
    {
        return new static(
            accountId: $this->accountId,
            firstFactor: $this->firstFactor,
            origin: $this->origin,
            stage: PendingStage::ENROLLMENT,
            intendedUrl: $this->intendedUrl,
            heldAt: $this->heldAt,
            epoch: $this->epoch,
            secondFactorPassed: true,
            pendingChallengeId: $this->pendingChallengeId,
            rememberMe: $this->rememberMe,
        );
    }

    /**
     * Convert it to the pending sign-in of the account it holds.
     */
    public function toPendingSignIn(Model&KeystoneUser $account): PendingSignIn
    {
        return new PendingSignIn(
            account: $account,
            firstFactor: $this->firstFactor,
            origin: $this->origin,
            stage: $this->stage,
            intendedUrl: $this->intendedUrl,
            heldAt: $this->heldAt,
            epoch: $this->epoch,
            secondFactorPassed: $this->secondFactorPassed,
            pendingChallengeId: $this->pendingChallengeId,
            rememberMe: $this->rememberMe,
        );
    }

    /**
     * Convert the pending sign-in to the values the session holds for it, tagged with its kind.
     *
     * @return array<string, mixed>
     */
    public function toSession(): array
    {
        return [
            'phase' => self::TAG,
            'account' => $this->accountId,
            'first_factor' => $this->firstFactor,
            'origin' => $this->origin->value,
            'stage' => $this->stage->value,
            'intended_url' => $this->intendedUrl,
            'held_at' => $this->heldAt->getTimestamp(),
            'epoch' => $this->epoch,
            'second_factor_passed' => $this->secondFactorPassed,
            'pending_challenge_id' => $this->pendingChallengeId,
            'remember_me' => $this->rememberMe->value,
        ];
    }
}
