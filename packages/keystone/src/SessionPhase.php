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
     * Hold the phase, in place of whatever the session held.
     */
    public function put(Phase $phase): void
    {
        $this->session->put($this->key, $phase->toSession());
    }

    /**
     * Get the phase the session holds as it was held, run out or not, forgetting one that can't be read.
     */
    public function held(): ?Phase
    {
        $values = $this->session->get($this->key);

        if ($values === null) {
            return null;
        }

        $phase = is_array($values) ? $this->toPhase($values) : null;

        if ($phase === null) {
            $this->forget();
        }

        return $phase;
    }

    /**
     * Get the phase of the kind while it is live, forgetting one of that kind that ran out or starts in the future.
     *
     * @template TPhase of Phase
     *
     * @param  class-string<TPhase>  $kind
     * @return TPhase|null
     */
    public function live(string $kind): ?Phase
    {
        $held = $this->held();

        if (! $held instanceof $kind) {
            return null;
        }

        if (! $this->isLive($held)) {
            $this->forget();

            return null;
        }

        return $held;
    }

    /**
     * Determine if the phase has started and not yet ended.
     */
    public function isLive(Phase $phase): bool
    {
        return ! $phase->startsAt()->isFuture() && $phase->endsAt()->greaterThan(Date::now());
    }

    /**
     * Forget the phase the session holds.
     */
    public function forget(): void
    {
        $this->session->forget($this->key);
    }

    /**
     * Get the time the phase ends: a pending sign-in's even once it ran out, any other only while it is live.
     */
    public function endsAt(): ?CarbonImmutable
    {
        $held = $this->held();

        if ($held === null || $held instanceof HeldSignIn) {
            return $held?->endsAt();
        }

        if (! $this->isLive($held)) {
            $this->forget();

            return null;
        }

        return $held->endsAt();
    }

    /**
     * Read the held values into the phase their tag names, or null when they can't be read.
     *
     * @param  array<mixed>  $values
     */
    protected function toPhase(array $values): ?Phase
    {
        return match ($values['phase'] ?? null) {
            HeldSignIn::TAG => HeldSignIn::fromSession($values),
            Registering::TAG => Registering::fromSession($values),
            SudoInProgress::TAG => SudoInProgress::fromSession($values),
            SudoGrant::TAG => SudoGrant::fromSession($values, $this->sudoLifetimeSeconds),
            default => null,
        };
    }
}
