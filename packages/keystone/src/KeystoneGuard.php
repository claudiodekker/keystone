<?php

namespace ClaudioDekker\Keystone;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use ClaudioDekker\Keystone\Exceptions\Barred;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;

/**
 * @internal
 *
 * @property EloquentUserProvider $provider
 */
class KeystoneGuard extends SessionGuard
{
    /**
     * The request attribute set when Keystone ends the session during the request.
     */
    public const string ENDED_SESSION = 'keystone.ended_session';

    /**
     * The request attribute set when Keystone ended the session during the request because it outlived its absolute lifetime.
     */
    public const string EXPIRED_SESSION = 'keystone.expired_session';

    /**
     * The request attribute set when Keystone ended the session during the request because its account newly owes enrollment.
     */
    public const string DEMOTED_SESSION = 'keystone.demoted_session';

    /**
     * Get the currently authenticated user.
     *
     * @return Authenticatable|null
     */
    public function user()
    {
        if ($this->loggedOut) {
            return null;
        }

        if (! is_null($this->user)) {
            return $this->user;
        }

        $id = $this->session->get($this->getName());

        if (is_null($id)) {
            return null;
        }

        $user = $this->retrieveAccount($id);

        if (is_null($user) || ! $this->isLive($user)) {
            $this->endSession();

            return null;
        }

        if ($this->hasExpired()) {
            $this->expire($user);

            return null;
        }

        if ((new SignInDecision)->owesEnrollment($user)) {
            $this->demote($user);

            return null;
        }

        $this->fireAuthenticatedEvent($this->user = $user);

        return $this->user;
    }

    /**
     * Start a signed-in session for the user, stamped with the credential epoch they were read with.
     *
     * @throws Barred
     */
    public function signIn(Model&KeystoneUser $user): void
    {
        $account = $this->retrieveAccount($user->getAuthIdentifier());

        if (is_null($account)) {
            throw Barred::missing();
        }

        if (! $this->isActive($account)) {
            throw Barred::inactive();
        }

        $this->changeAuthLevel();

        $this->session->forget($this->pendingKey());
        $this->session->put($this->getName(), $user->getAuthIdentifier());
        $this->session->put($this->epochKey(), $this->epochOf($user));
        $this->session->put($this->signedInAtKey(), Date::now()->getTimestamp());

        $this->fireLoginEvent($user);

        $this->setUser($user);
    }

    /**
     * Hold the account's sign-in until it passes the stage, replacing any pending one.
     */
    public function hold(Model&KeystoneUser $account, string $firstFactor, PendingStage $stage, string $intendedUrl): void
    {
        $this->changeAuthLevel();

        $this->session->put($this->pendingKey(), [
            'account' => $account->getAuthIdentifier(),
            'first_factor' => $firstFactor,
            'origin' => PendingOrigin::LOGIN->value,
            'stage' => $stage->value,
            'intended_url' => $intendedUrl,
            'epoch' => $this->epochOf($account),
            'held_at' => Date::now()->getTimestamp(),
            'second_factor_passed' => false,
        ]);
    }

    /**
     * Note that the pending sign-in of an active account passed its second factor, holding it on at enrollment for what it still owes and keeping the time it was held.
     */
    public function passSecondFactor(): void
    {
        $held = $this->session->get($this->pendingKey());

        if (! is_array($held)) {
            throw new LogicException('The session holds no pending sign-in to move on.');
        }

        $account = $this->retrieveAccount($held['account'] ?? null);

        if (is_null($account) || ! $this->isActive($account)) {
            throw new LogicException('The account being moved on no longer exists, or is disabled or suspended.');
        }

        $this->changeAuthLevel();

        $this->session->put($this->pendingKey(), [
            ...$held,
            'stage' => PendingStage::ENROLLMENT->value,
            'second_factor_passed' => true,
        ]);
    }

    /**
     * Get the session's live pending sign-in, dropping or voiding one that is no longer valid.
     */
    public function pending(): ?PendingSignIn
    {
        $held = $this->session->get($this->pendingKey());

        if (! is_array($held)) {
            return null;
        }

        $heldAt = CarbonImmutable::createFromTimestamp($held['held_at']);

        if ($heldAt->isFuture() || $heldAt->addSeconds(PendingSignIn::LIFETIME_SECONDS)->lessThanOrEqualTo(Date::now())) {
            $this->forgetPending();

            return null;
        }

        $account = $this->retrieveAccount($held['account']);

        if (is_null($account) || ! $this->isActive($account) || $this->epochOf($account) !== $held['epoch']) {
            $this->voidPending($account);

            return null;
        }

        return new PendingSignIn(
            account: $account,
            firstFactor: $held['first_factor'],
            origin: PendingOrigin::from($held['origin']),
            stage: PendingStage::from($held['stage']),
            intendedUrl: $held['intended_url'],
            heldAt: $heldAt,
            epoch: $held['epoch'],
            secondFactorPassed: $held['second_factor_passed'],
        );
    }

    /**
     * Determine if the session holds a pending sign-in at the stage, as it was held.
     */
    public function isPendingAt(PendingStage $stage): bool
    {
        return ($this->session->get($this->pendingKey())['stage'] ?? null) === $stage->value;
    }

    /**
     * Get the id of the account the session names, signed in or pending.
     */
    public function namedAccountId(): int|string|null
    {
        return $this->id() ?? $this->session->get($this->pendingKey())['account'] ?? null;
    }

    /**
     * Drop the pending sign-in, leaving a guest.
     */
    public function forgetPending(): void
    {
        $this->changeAuthLevel();

        $this->session->forget($this->pendingKey());
    }

    /**
     * Get the ceremony slots, which end no later than the sign-in, pending or signed in, that opens them.
     */
    public function slots(): CeremonySlots
    {
        return new CeremonySlots($this->session, $this->phaseEndsAt());
    }

    /**
     * Keep the session signed in across a move of its account's credential epoch, on a new session id, when it was live on the epoch that moved.
     */
    public function carryOver(Model&KeystoneUser $account, int $movedFrom): void
    {
        $signedInAs = $this->session->get($this->getName());

        if (is_null($signedInAs) || (string) $signedInAs !== (string) $account->getAuthIdentifier()) {
            return;
        }

        if ($this->session->get($this->epochKey()) !== $movedFrom) {
            return;
        }

        $this->rotate();

        $this->session->put($this->epochKey(), $this->epochOf($account));
    }

    /**
     * End the signed-in session and regenerate its CSRF token.
     */
    public function signOut(): void
    {
        $this->logout();

        $this->session->invalidate();
        $this->session->regenerateToken();

        $this->markSessionEnded();
    }

    /**
     * Get the time the session signed in.
     */
    public function signedInAt(): ?CarbonInterface
    {
        $timestamp = $this->session->get($this->signedInAtKey());

        return is_int($timestamp) ? Date::createFromTimestamp($timestamp) : null;
    }

    /**
     * Get a new instance of the user model.
     *
     * @return Model&KeystoneUser
     */
    public function userModel(): Model
    {
        /** @var Model&KeystoneUser */
        return $this->provider->createModel();
    }

    /**
     * Refuse to validate credentials; only Keystone signs anyone in.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function validate(#[\SensitiveParameter] array $credentials = [])
    {
        return false;
    }

    /**
     * Refuse to sign in with credentials; only Keystone signs anyone in.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function attempt(#[\SensitiveParameter] array $credentials = [], $remember = false)
    {
        return false;
    }

    /**
     * Refuse to sign in with credentials; only Keystone signs anyone in.
     *
     * @param  array<string, mixed>  $credentials
     * @param  array<int, callable>|callable|null  $callbacks
     */
    public function attemptWhen(#[\SensitiveParameter] array $credentials = [], $callbacks = null, $remember = false)
    {
        return false;
    }

    /**
     * Refuse to sign the user in; only Keystone signs anyone in.
     *
     * @param  bool  $remember
     */
    public function login(Authenticatable $user, $remember = false)
    {
        //
    }

    /**
     * Refuse to set the user for this request; only Keystone signs anyone in.
     *
     * @param  mixed  $id
     */
    public function onceUsingId($id)
    {
        return false;
    }

    /**
     * Refuse to sign the user in; only Keystone signs anyone in.
     *
     * @param  mixed  $id
     * @param  bool  $remember
     */
    public function loginUsingId($id, $remember = false)
    {
        return false;
    }

    /**
     * Refuse to end other sessions by password; Keystone moves the credential epoch instead.
     *
     * @param  string  $password
     */
    public function logoutOtherDevices(#[\SensitiveParameter] $password)
    {
        return null;
    }

    /**
     * Leave the app's remember token alone; Keystone never reads or writes it.
     */
    protected function cycleRememberToken(Authenticatable $user)
    {
        //
    }

    /**
     * Read the account and its Keystone state in one query, bypassing every global scope.
     *
     * @return (Model&KeystoneUser)|null
     */
    protected function retrieveAccount(mixed $id): ?Model
    {
        $model = $this->userModel();

        /** @var (Model&KeystoneUser)|null */
        return $model->newQueryWithoutScopes()->where($model->getAuthIdentifierName(), $id)->first();
    }

    /**
     * Determine if the account is active and still on the session's credential epoch.
     */
    protected function isLive(Model&KeystoneUser $account): bool
    {
        return $this->isActive($account)
            && $this->session->get($this->epochKey()) === $this->epochOf($account);
    }

    /**
     * Determine if the account is neither deleted, invalidated nor suspended.
     */
    protected function isActive(Model&KeystoneUser $account): bool
    {
        return ! (new SignInDecision)->isBarred($account);
    }

    /**
     * Get the credential epoch the account was read with.
     */
    protected function epochOf(Model&KeystoneUser $account): int
    {
        return (int) $account->getRawOriginal('credential_epoch');
    }

    /**
     * Determine if the session outlived its absolute lifetime, or has no believable sign-in time to count it from.
     */
    protected function hasExpired(): bool
    {
        if (config('keystone.session.absolute_lifetime_seconds') === null) {
            return false;
        }

        $signedInAt = $this->signedInAt();

        if (is_null($signedInAt) || $signedInAt->isFuture()) {
            return true;
        }

        $lifetimeSeconds = config()->integer('keystone.session.absolute_lifetime_seconds');

        return $signedInAt->addSeconds($lifetimeSeconds)->lessThanOrEqualTo(Date::now());
    }

    /**
     * End the session because it outlived its absolute lifetime, telling the user and then recording why, so a failure reported while recording finds nobody signed in.
     */
    protected function expire(Model&KeystoneUser $account): void
    {
        $this->endSession();

        $this->session->flash(Status::SESSION_KEY, Status::SESSION_EXPIRED->value);

        $this->getRequest()->attributes->set(self::EXPIRED_SESSION, true);

        (new SecurityEventRecorder)->record(
            SecurityEventType::SESSION_ENDED,
            account: $account,
            reason: 'expired',
        );
    }

    /**
     * End the session because its account newly owes enrollment, tell the user to sign in again, and record why.
     */
    protected function demote(Model&KeystoneUser $account): void
    {
        $this->endSession();

        $this->session->flash(Status::SESSION_KEY, Status::ENROLLMENT_OWED->value);

        $this->getRequest()->attributes->set(self::DEMOTED_SESSION, true);

        (new SecurityEventRecorder)->record(
            SecurityEventType::SESSION_ENDED,
            account: $account,
            reason: 'demoted',
        );
    }

    /**
     * Drop a pending sign-in its account's credential epoch left behind, recording that it was voided.
     */
    protected function voidPending((Model&KeystoneUser)|null $account): void
    {
        $this->forgetPending();

        if (is_null($account)) {
            return;
        }

        (new SecurityEventRecorder)->record(
            SecurityEventType::SIGN_IN_VOIDED,
            account: $account,
        );
    }

    /**
     * Get the time the session's current phase ends: a pending sign-in's end, a sign-in's absolute lifetime, or never.
     */
    protected function phaseEndsAt(): ?CarbonInterface
    {
        $heldAt = $this->session->get($this->pendingKey())['held_at'] ?? null;
        $lifetimeSeconds = config('keystone.session.absolute_lifetime_seconds');

        return match (true) {
            $this->session->has($this->getName()) => is_int($lifetimeSeconds) ? $this->signedInAt()?->addSeconds($lifetimeSeconds) : null,
            is_int($heldAt) => Date::createFromTimestamp($heldAt)->addSeconds(PendingSignIn::LIFETIME_SECONDS),
            default => null,
        };
    }

    /**
     * End the session and forget its user.
     */
    protected function endSession(): void
    {
        $this->session->invalidate();

        $this->user = null;

        $this->markSessionEnded();
    }

    /**
     * Mark the request as the one that ended the session, so its response clears the site's data.
     */
    protected function markSessionEnded(): void
    {
        $this->getRequest()->attributes->set(self::ENDED_SESSION, true);
    }

    /**
     * Rotate the session id and close every ceremony slot, as every change of auth level does.
     */
    protected function changeAuthLevel(): void
    {
        $this->rotate();

        $this->slots()->flush();
    }

    /**
     * Rotate the session id, keeping its data.
     */
    protected function rotate(): void
    {
        $this->session->regenerate(true);
    }

    /**
     * Get the session key holding the credential epoch the session was stamped with.
     */
    protected function epochKey(): string
    {
        return 'keystone_epoch_'.$this->name;
    }

    /**
     * Get the session key holding the pending sign-in.
     */
    protected function pendingKey(): string
    {
        return 'keystone_pending_'.$this->name;
    }

    /**
     * Get the session key holding the time the session signed in.
     */
    protected function signedInAtKey(): string
    {
        return 'keystone_signed_in_at_'.$this->name;
    }
}
