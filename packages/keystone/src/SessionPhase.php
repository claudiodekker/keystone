<?php

namespace ClaudioDekker\Keystone;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Date;

/**
 * @internal
 */
class SessionPhase
{
    /**
     * The tag of a pending sign-in.
     */
    protected const string PENDING = 'pending';

    /**
     * The tag of a registration.
     */
    protected const string REGISTERING = 'registering';

    /**
     * The tag of a sudo-in-progress.
     */
    protected const string SUDO_IN_PROGRESS = 'sudo_in_progress';

    /**
     * The tag of a sudo grant.
     */
    protected const string SUDO_GRANTED = 'sudo_granted';

    /**
     * Create a new session phase instance.
     */
    public function __construct(
        protected Session $session,
        protected string $key,
        protected int $sudoLifetimeSeconds,
    ) {
        //
    }

    /**
     * Hold the record, in place of whatever the session held.
     */
    public function put(HeldSignIn|Registering|SudoInProgress|SudoGrant $record): void
    {
        $this->session->put($this->key, match (true) {
            $record instanceof HeldSignIn => [
                'phase' => self::PENDING,
                'account' => $record->accountId,
                'first_factor' => $record->firstFactor,
                'origin' => $record->origin->value,
                'stage' => $record->stage->value,
                'intended_url' => $record->intendedUrl,
                'held_at' => $record->heldAt->getTimestamp(),
                'epoch' => $record->epoch,
                'second_factor_passed' => $record->secondFactorPassed,
                'pending_challenge_id' => $record->pendingChallengeId,
                'remember_me' => $record->rememberMe->value,
            ],
            $record instanceof Registering => [
                'phase' => self::REGISTERING,
                'address' => $record->address,
                'started_at' => $record->startedAt->getTimestamp(),
                'verified' => $record->verified,
            ],
            $record instanceof SudoInProgress => [
                'phase' => self::SUDO_IN_PROGRESS,
                'started_at' => $record->startedAt->getTimestamp(),
                'intended_url' => $record->intendedUrl,
                'first_factor' => $record->firstFactor,
            ],
            $record instanceof SudoGrant => [
                'phase' => self::SUDO_GRANTED,
                'granted_at' => $record->grantedAt->getTimestamp(),
                'subnet' => $record->subnet->cidr,
            ],
        });
    }

    /**
     * Get the record of the kind while it is live, forgetting one of that kind that ran out or starts in the future.
     *
     * @template TRecord of HeldSignIn|Registering|SudoInProgress|SudoGrant
     *
     * @param  class-string<TRecord>  $kind
     * @return TRecord|null
     */
    public function live(string $kind): HeldSignIn|Registering|SudoInProgress|SudoGrant|null
    {
        $held = $this->held();

        if (! $held instanceof $kind) {
            return null;
        }

        if ($this->startOf($held)->isFuture() || $this->endOf($held)->lessThanOrEqualTo(Date::now())) {
            $this->forget();

            return null;
        }

        return $held;
    }

    /**
     * Get the record the session holds as it was held, run out or not, forgetting one that can't be read.
     */
    public function held(): HeldSignIn|Registering|SudoInProgress|SudoGrant|null
    {
        $held = $this->session->get($this->key);

        if ($held === null) {
            return null;
        }

        $record = is_array($held) ? $this->toRecord($held) : null;

        if ($record === null) {
            $this->forget();
        }

        return $record;
    }

    /**
     * Read the held value into the record its tag names, or null when it can't be read.
     *
     * @param  array<mixed>  $held
     */
    protected function toRecord(array $held): HeldSignIn|Registering|SudoInProgress|SudoGrant|null
    {
        return match ($held['phase'] ?? null) {
            self::PENDING => $this->toHeldSignIn($held),
            self::REGISTERING => $this->toRegistering($held),
            self::SUDO_IN_PROGRESS => $this->toSudoInProgress($held),
            self::SUDO_GRANTED => $this->toSudoGrant($held),
            default => null,
        };
    }

    /**
     * Forget the record the session holds.
     */
    public function forget(): void
    {
        $this->session->forget($this->key);
    }

    /**
     * Get the time the record ends: a pending sign-in's even once it ran out, any other only while it is live.
     */
    public function endsAt(): ?CarbonImmutable
    {
        $held = $this->held();

        if ($held === null || $held instanceof HeldSignIn) {
            return $held?->endsAt();
        }

        $live = $this->live($held::class);

        return $live === null ? null : $this->endOf($live);
    }

    /**
     * Get the time the record starts counting from.
     */
    protected function startOf(HeldSignIn|Registering|SudoInProgress|SudoGrant $record): CarbonImmutable
    {
        return match (true) {
            $record instanceof HeldSignIn => $record->heldAt,
            $record instanceof Registering, $record instanceof SudoInProgress => $record->startedAt,
            $record instanceof SudoGrant => $record->grantedAt,
        };
    }

    /**
     * Get the time the record ends.
     */
    protected function endOf(HeldSignIn|Registering|SudoInProgress|SudoGrant $record): CarbonImmutable
    {
        return match (true) {
            $record instanceof HeldSignIn, $record instanceof SudoInProgress => $record->endsAt(),
            $record instanceof Registering, $record instanceof SudoGrant => $record->endsAt,
        };
    }

    /**
     * Read a held pending sign-in, or null when any field is missing or of the wrong type.
     *
     * @param  array<mixed>  $held
     */
    protected function toHeldSignIn(array $held): ?HeldSignIn
    {
        $account = $held['account'] ?? null;
        $origin = is_string($held['origin'] ?? null) ? PendingOrigin::tryFrom($held['origin']) : null;
        $stage = is_string($held['stage'] ?? null) ? PendingStage::tryFrom($held['stage']) : null;
        $rememberMe = is_string($held['remember_me'] ?? null) ? RememberMe::tryFrom($held['remember_me']) : null;
        $pendingChallengeId = $held['pending_challenge_id'] ?? null;

        if (! is_int($account) && ! is_string($account)) {
            return null;
        }

        if ($origin === null || $stage === null || $rememberMe === null) {
            return null;
        }

        if (! is_string($held['first_factor'] ?? null) || ! is_string($held['intended_url'] ?? null)) {
            return null;
        }

        if (! is_int($held['held_at'] ?? null) || ! is_int($held['epoch'] ?? null) || ! is_bool($held['second_factor_passed'] ?? null)) {
            return null;
        }

        if (! is_int($pendingChallengeId) && $pendingChallengeId !== null) {
            return null;
        }

        return new HeldSignIn(
            accountId: $account,
            firstFactor: $held['first_factor'],
            origin: $origin,
            stage: $stage,
            intendedUrl: $held['intended_url'],
            heldAt: CarbonImmutable::createFromTimestamp($held['held_at']),
            epoch: $held['epoch'],
            secondFactorPassed: $held['second_factor_passed'],
            pendingChallengeId: $pendingChallengeId,
            rememberMe: $rememberMe,
        );
    }

    /**
     * Read a held registration, or null when any field is missing or of the wrong type.
     *
     * @param  array<mixed>  $held
     */
    protected function toRegistering(array $held): ?Registering
    {
        if (! is_string($held['address'] ?? null) || ! is_int($held['started_at'] ?? null) || ! is_bool($held['verified'] ?? null)) {
            return null;
        }

        return new Registering(
            address: $held['address'],
            startedAt: CarbonImmutable::createFromTimestamp($held['started_at']),
            verified: $held['verified'],
        );
    }

    /**
     * Read a held sudo-in-progress, or null when any field is missing or of the wrong type.
     *
     * @param  array<mixed>  $held
     */
    protected function toSudoInProgress(array $held): ?SudoInProgress
    {
        $firstFactor = $held['first_factor'] ?? null;

        if (! is_int($held['started_at'] ?? null) || ! is_string($held['intended_url'] ?? null)) {
            return null;
        }

        return new SudoInProgress(
            intendedUrl: $held['intended_url'],
            startedAt: CarbonImmutable::createFromTimestamp($held['started_at']),
            firstFactor: is_string($firstFactor) ? $firstFactor : null,
        );
    }

    /**
     * Read a held sudo grant, ending the sudo lifetime after it was granted, or null when any field is missing or of the wrong type.
     *
     * @param  array<mixed>  $held
     */
    protected function toSudoGrant(array $held): ?SudoGrant
    {
        if (! is_int($held['granted_at'] ?? null) || ! is_string($held['subnet'] ?? null)) {
            return null;
        }

        $grantedAt = CarbonImmutable::createFromTimestamp($held['granted_at']);

        return new SudoGrant($grantedAt, $grantedAt->addSeconds($this->sudoLifetimeSeconds), new Subnet($held['subnet']));
    }
}
