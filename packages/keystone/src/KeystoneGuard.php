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
use LogicException;
use Symfony\Component\HttpFoundation\Cookie;

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
     * The request attribute set when Keystone ended the session, or refused to restore one, during the request because its account newly owes enrollment.
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
            return $this->restore();
        }

        $user = $this->retrieveAccount($id);

        if (is_null($user) || ! $this->isLive($user)) {
            $this->endSession();

            return null;
        }

        if ($this->hasExpired()) {
            $restored = $this->restore();

            // A failure reported while refusing the cookie asks who is signed in, which expires the session there and then.
            if (is_null($restored) && $this->session->has($this->getName())) {
                $this->expire($user);
            }

            return $restored;
        }

        if ((new SignInDecision)->owesEnrollment($user)) {
            $this->demote($user);

            return null;
        }

        $this->fireAuthenticatedEvent($this->user = $user);

        return $this->user;
    }

    /**
     * Start a signed-in session for the user, stamped with the credential epoch they were read with and remembered on this device when asked, returning whether the browser was a known device of theirs.
     *
     * @throws Barred
     */
    public function signIn(Model&KeystoneUser $user, RememberMe $rememberMe = RememberMe::NOT_ASKED): bool
    {
        $account = $this->retrieveAccount($user->getAuthIdentifier());

        if (is_null($account)) {
            throw Barred::missing();
        }

        if (! $this->isActive($account)) {
            throw Barred::inactive();
        }

        $knownDevice = $this->recognizeDevice($user);

        $this->startSignedInSession($user, $this->remember($user, $rememberMe));

        $this->bringSudo($user);

        $this->fireLoginEvent($user);

        $this->setUser($user);

        return $knownDevice;
    }

    /**
     * Hold the account's sign-in until it passes the stage, keeping whether it asked to be remembered and replacing any pending one.
     */
    public function hold(Model&KeystoneUser $account, string $firstFactor, PendingStage $stage, string $intendedUrl, RememberMe $rememberMe = RememberMe::NOT_ASKED): void
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
            'pending_challenge_id' => $stage === PendingStage::CHALLENGE ? $this->openPendingChallenge($account) : null,
            'remember_me' => $rememberMe->value,
        ]);
    }

    /**
     * Note that the pending sign-in of an active account passed its second factor, holding it on at enrollment for what it still owes and keeping the time it was held.
     *
     * @throws Barred
     */
    public function passSecondFactor(): void
    {
        $held = $this->session->get($this->pendingKey());

        if (! is_array($held)) {
            throw new LogicException('The session holds no pending sign-in to move on.');
        }

        $account = $this->retrieveAccount($held['account'] ?? null);

        if (is_null($account)) {
            throw Barred::missing();
        }

        if (! $this->isActive($account)) {
            throw Barred::inactive();
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
            pendingChallengeId: $held['pending_challenge_id'],
            rememberMe: RememberMe::from($held['remember_me']),
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
     * Keep the session signed in across a move of its account's credential epoch, on a new session id and a new remember-me value, when it was live on the epoch that moved.
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

        $this->reissueRememberCookie($account, $movedFrom);
    }

    /**
     * End the signed-in session, forget the token that remembers it on this device, and regenerate its CSRF token.
     */
    public function signOut(): void
    {
        // The token is forgotten first, because ending the session drops its id.
        $this->stopRemembering();

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
     * Get the subnet the request comes from, or null when its IP address can't be parsed.
     */
    public function subnet(): ?Subnet
    {
        return Subnet::of($this->getRequest()->getClientIp());
    }

    /**
     * Get the session's live sudo grant, forgetting one that outlived keystone.sudo.lifetime_seconds or has no believable time to count it from.
     */
    public function sudoGrant(): ?SudoGrant
    {
        $held = $this->session->get($this->sudoKey());

        if (! is_array($held)) {
            return null;
        }

        $grantedAt = CarbonImmutable::createFromTimestamp($held['granted_at']);
        $endsAt = $grantedAt->addSeconds(config()->integer('keystone.sudo.lifetime_seconds'));

        if ($grantedAt->isFuture() || $endsAt->lessThanOrEqualTo(Date::now())) {
            $this->session->forget($this->sudoKey());

            return null;
        }

        return new SudoGrant($endsAt, new Subnet($held['subnet']));
    }

    /**
     * End sudo, dropping the session's grant on a new session id. Safe to call without one.
     */
    public function endSudo(): void
    {
        $this->changeAuthLevel();
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
     * Refuse to sign the user in by throwing, so a caller expecting a session doesn't carry on without one.
     *
     * @param  bool  $remember
     *
     * @throws LogicException
     */
    public function login(Authenticatable $user, $remember = false)
    {
        throw new LogicException("Auth::login() can't sign anyone in; only Keystone signs anyone in.");
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
     * @return null
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
     * Determine if the browser is a known device of the account, then hand it a fresh device cookie every account that knew it moves to.
     *
     * A browser holding a value that a later sign-in replaced shares its cookie with another browser, so the owner is told and the account knows only this browser from here on.
     */
    protected function recognizeDevice(Model&KeystoneUser $account): bool
    {
        $request = $this->getRequest();
        $previous = KnownDevices::cookieOf($request);
        $devices = new KnownDevices($account);

        if ($previous !== null && $devices->isReused($account->getKey(), $previous)) {
            (new SecurityEventRecorder)->record(SecurityEventType::DEVICE_COOKIE_REUSED, account: $account);
        }

        $known = $devices->isKnown($account->getKey(), $previous);
        $value = $devices->remember($account->getKey(), previous: $previous, userAgent: $request->headers->get('User-Agent'), ipAddress: $request->getClientIp());

        $this->getCookieJar()->queue(Cookie::create(
            KnownDevices::COOKIE,
            $value,
            expire: Date::now()->addSeconds(KnownDevices::retentionSeconds()),
            secure: true,
            sameSite: Cookie::SAMESITE_LAX,
        ));

        return $known;
    }

    /**
     * Restore the sign-in the request's remember-me cookie remembers, or refuse a dead cookie.
     *
     * @return (Model&KeystoneUser)|null
     */
    protected function restore(): ?Model
    {
        $value = $this->presentedRememberCookie();

        if ($value === null) {
            return null;
        }

        $token = (new RememberTokens($this->userModel()))->find($value);
        $account = is_null($token) ? null : $this->retrieveAccount($token->accountId);

        if (is_null($token) || is_null($account) || ! $this->remembers($token, $account)) {
            $this->refuseRestore($token, $account, reason: 'keystone.dead_remember_cookie');

            return null;
        }

        if ((new SignInDecision)->owesEnrollment($account)) {
            $this->refuseOwingRestore($token, $account);

            return null;
        }

        return $this->signInRemembered($account, $token);
    }

    /**
     * Get the value of the remember-me cookie the request carries, when remember-me is on and the session can take a restored sign-in.
     */
    protected function presentedRememberCookie(): ?string
    {
        if (! RememberTokens::isOffered()) {
            return null;
        }

        // A session that has yet to start takes the id the browser sent
        // once it does, so restoring now would leave the sign-in on
        // an id the browser chose, not a rotated one.
        if (! $this->session->isStarted()) {
            return null;
        }

        return RememberTokens::cookieOf($this->getRequest());
    }

    /**
     * Sign the account in on the token that remembers it, changing neither the token nor a cookie, and record the remembered return.
     *
     * @return Model&KeystoneUser
     */
    protected function signInRemembered(Model&KeystoneUser $account, RememberToken $token): Model
    {
        $knownDevice = (new KnownDevices($account))->isKnown($account->getKey(), KnownDevices::cookieOf($this->getRequest()));

        $this->startSignedInSession($account, $token->id);

        $this->viaRemember = true;

        $this->fireLoginEvent($account, true);

        $this->setUser($account);

        (new SecurityEventRecorder)->record(
            SecurityEventType::SIGNED_IN,
            account: $account,
            reason: 'remembered',
            knownDevice: $knownDevice,
        );

        return $account;
    }

    /**
     * Determine if the token still remembers the account: it is live on the account's credential epoch, and the account is active.
     */
    protected function remembers(RememberToken $token, Model&KeystoneUser $account): bool
    {
        return $token->isLiveOn($this->epochOf($account)) && $this->isActive($account);
    }

    /**
     * Refuse the remember-me cookie: drop it from the browser and the request, forget its token, and record why.
     *
     * The cookie goes first, so a failure reported while recording finds none to refuse again.
     */
    protected function refuseRestore(?RememberToken $token, (Model&KeystoneUser)|null $account, string $reason): void
    {
        $this->dropRememberCookie();

        if (! is_null($token)) {
            (new RememberTokens($this->userModel()))->forget($token->id);
        }

        (new SecurityEventRecorder)->record(
            SecurityEventType::REQUEST_REJECTED,
            account: $account,
            reason: $reason,
        );
    }

    /**
     * Refuse the remember-me cookie of an account that newly owes enrollment, telling the user to sign in again.
     */
    protected function refuseOwingRestore(RememberToken $token, Model&KeystoneUser $account): void
    {
        $this->session->flash(Status::SESSION_KEY, Status::ENROLLMENT_OWED->value);

        $this->getRequest()->attributes->set(self::DEMOTED_SESSION, true);

        $this->refuseRestore($token, $account, reason: 'keystone.enrollment_owed');
    }

    /**
     * Issue the account a remember token for this browser when the sign-in asked for one and remember-me is on, returning the token's id.
     */
    protected function remember(Model&KeystoneUser $account, RememberMe $rememberMe): ?int
    {
        if ($rememberMe === RememberMe::NOT_ASKED || ! RememberTokens::isOffered()) {
            return null;
        }

        [$id, $cookie] = (new RememberTokens($account))->issue($account->getKey(), $this->epochOf($account));

        $this->getCookieJar()->queue($cookie);

        return $id;
    }

    /**
     * Start the account's signed-in session on a new session id, in place of any pending sign-in, stamped with its credential epoch, the time and the token that remembers it.
     */
    protected function startSignedInSession(Model&KeystoneUser $account, ?int $rememberTokenId): void
    {
        $this->changeAuthLevel();

        $this->session->forget($this->pendingKey());
        $this->session->put($this->getName(), $account->getAuthIdentifier());
        $this->session->put($this->epochKey(), $this->epochOf($account));
        $this->session->put($this->signedInAtKey(), Date::now()->getTimestamp());
        $this->session->put($this->rememberKey(), $rememberTokenId);
    }

    /**
     * Grant the session that just signed in its sudo and record it, unless the address it signed in from can't be parsed.
     */
    protected function bringSudo(Model&KeystoneUser $account): void
    {
        $subnet = $this->subnet();

        if (is_null($subnet)) {
            return;
        }

        $this->stampSudo($subnet);

        (new SecurityEventRecorder)->record(
            SecurityEventType::SUDO_GRANTED,
            account: $account,
            reason: 'keystone.sign_in',
        );
    }

    /**
     * Write a sudo grant from now, bound to the subnet.
     */
    protected function stampSudo(Subnet $subnet): void
    {
        $this->session->put($this->sudoKey(), [
            'granted_at' => Date::now()->getTimestamp(),
            'subnet' => $subnet->cidr,
        ]);
    }

    /**
     * Hand the session's remember token a new value on the epoch its account moved to, so a copy of the old value dies with every other session.
     */
    protected function reissueRememberCookie(Model&KeystoneUser $account, int $movedFrom): void
    {
        $id = $this->session->get($this->rememberKey());

        if (! is_int($id)) {
            return;
        }

        $cookie = (new RememberTokens($account))->reissue($id, movedFrom: $movedFrom, movedTo: $this->epochOf($account));

        if ($cookie !== null) {
            $this->getCookieJar()->queue($cookie);
        }
    }

    /**
     * Forget the token that remembers the session on this device and drop its cookie.
     */
    protected function stopRemembering(): void
    {
        $id = $this->session->get($this->rememberKey());

        if (is_int($id)) {
            (new RememberTokens($this->userModel()))->forget($id);
        }

        $this->dropRememberCookie();
    }

    /**
     * Make the browser drop its remember-me cookie, and drop it from this request.
     */
    protected function dropRememberCookie(): void
    {
        $request = $this->getRequest();

        if (! $request->cookies->has(RememberTokens::COOKIE)) {
            return;
        }

        $this->getCookieJar()->queue(RememberTokens::expiredCookie());

        $request->cookies->remove(RememberTokens::COOKIE);
    }

    /**
     * Get the id of the pending challenge the hold of the account keeps: the one the sign-in it replaces opened, a new one, or none for a known device or when opening it fails.
     */
    protected function openPendingChallenge(Model&KeystoneUser $account): ?int
    {
        $request = $this->getRequest();

        if ((new KnownDevices($account))->isKnown($account->getKey(), KnownDevices::cookieOf($request))) {
            return null;
        }

        // Postgres aborts the whole transaction a failed query ran in, so the queries get a savepoint of their own to fail in.
        return rescue(fn () => $account->getConnection()->transaction(function () use ($account, $request) {
            $challenges = new PendingChallenges($account);
            $replaced = $this->replacedPendingChallengeId($account);

            if ($replaced !== null && $challenges->has($replaced)) {
                return $replaced;
            }

            return $challenges->open($account->getKey(), userAgent: $request->headers->get('User-Agent'), ipAddress: $request->getClientIp());
        }));
    }

    /**
     * Get the id of the pending challenge that the pending sign-in about to be replaced opened, when that sign-in is the account's.
     */
    protected function replacedPendingChallengeId(Model&KeystoneUser $account): ?int
    {
        $replaced = $this->session->get($this->pendingKey());

        if (! is_array($replaced) || (string) $replaced['account'] !== (string) $account->getAuthIdentifier()) {
            return null;
        }

        return $replaced['pending_challenge_id'];
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
     * End the session because its account newly owes enrollment, forget the token that remembers it on this device, tell the user to sign in again, and record why.
     */
    protected function demote(Model&KeystoneUser $account): void
    {
        $this->stopRemembering();

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
     * Rotate the session id, close every ceremony slot and drop sudo, as every change of auth level does.
     */
    protected function changeAuthLevel(): void
    {
        $this->rotate();

        $this->slots()->flush();

        $this->session->forget($this->sudoKey());
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
     * Get the session key holding the id of the remember token the session issued or restored.
     */
    protected function rememberKey(): string
    {
        return 'keystone_remember_'.$this->name;
    }

    /**
     * Get the session key holding the time the session signed in.
     */
    protected function signedInAtKey(): string
    {
        return 'keystone_signed_in_at_'.$this->name;
    }

    /**
     * Get the session key holding the sudo grant.
     */
    protected function sudoKey(): string
    {
        return 'keystone_sudo_'.$this->name;
    }
}
