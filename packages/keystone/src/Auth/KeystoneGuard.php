<?php

namespace ClaudioDekker\Keystone\Auth;

use ClaudioDekker\Keystone\Accounts\CredentialEpoch;
use ClaudioDekker\Keystone\Devices\KnownDevices;
use ClaudioDekker\Keystone\Exceptions\SessionGuardSignInException;
use ClaudioDekker\Keystone\Http\EndedSession;
use ClaudioDekker\Keystone\Http\Middleware\ClearSiteData;
use ClaudioDekker\Keystone\Models\UserRememberToken;
use ClaudioDekker\Keystone\SecurityEvents\Recorder;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use ClaudioDekker\Keystone\Session\RememberTokens;
use ClaudioDekker\Keystone\Session\SessionState;
use ClaudioDekker\Keystone\Session\Stage;
use ClaudioDekker\Keystone\SignIn\SignInDecision;
use ClaudioDekker\Keystone\Status;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\Request;

/**
 * @internal
 */
class KeystoneGuard extends SessionGuard
{
    /**
     * The request the guard serves, which Keystone's provider always sets.
     *
     * @var Request
     */
    protected $request;

    /**
     * The container the guard builds its per-request services from, since it outlives a request.
     */
    protected Container $container;

    /**
     * Set the container the guard builds its per-request services from.
     */
    public function setContainer(Container $container): static
    {
        $this->container = $container;

        return $this;
    }

    /**
     * Get the signed-in user, first ending a session that may no longer stand, so the request reads it as a guest's, unless a live remember-me cookie restores it.
     */
    public function user(): ?Authenticatable
    {
        if ($this->hasExpired() && ! $this->restore()) {
            $this->expire();
        }

        if ($this->hasLeftEpoch()) {
            $this->endOffEpoch();
        }

        if ($this->namesUnreadUser()) {
            $this->readUser();
        }

        if ($this->isGuest()) {
            $this->restore();
        }

        return parent::user();
    }

    /**
     * Refuse to sign anyone in with credentials.
     *
     * basic() signs in through here, so it is refused too.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function attempt(#[SensitiveParameter] array $credentials = [], $remember = false): bool
    {
        return false;
    }

    /**
     * Refuse to sign anyone in with credentials and callbacks.
     *
     * @param  array<string, mixed>  $credentials
     * @param  array<int, callable>|callable|null  $callbacks
     */
    public function attemptWhen(#[SensitiveParameter] array $credentials = [], $callbacks = null, $remember = false): bool
    {
        return false;
    }

    /**
     * Refuse to vouch for any credentials, a hash in the app's users.password included.
     *
     * once() and onceBasic() sign in through here, so they are refused too.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function validate(#[SensitiveParameter] array $credentials = []): bool
    {
        return false;
    }

    /**
     * Refuse to sign anyone in for one request by id.
     */
    public function onceUsingId($id): false
    {
        return false;
    }

    /**
     * Refuse to sign the user in by throwing, since the caller has no way to hear a refusal.
     */
    public function login(Authenticatable $user, $remember = false): never
    {
        throw SessionGuardSignInException::login();
    }

    /**
     * Refuse to sign anyone in by id.
     */
    public function loginUsingId($id, $remember = false): false
    {
        return false;
    }

    /**
     * Sign no other device out, since Keystone never reads or rehashes the app's users.password.
     */
    public function logoutOtherDevices(#[SensitiveParameter] $password): null
    {
        return null;
    }

    /**
     * Sign the user out and end the whole session, so nothing it held outlives them, Auth::logout() included, and clear the browser's site data.
     */
    public function logout(): void
    {
        parent::logout();

        $this->endSession();
    }

    /**
     * Determine if the session names a user whose sign-in is past the absolute lifetime.
     */
    protected function hasExpired(): bool
    {
        return $this->namesUnreadUser() && $this->state()->hasExpired();
    }

    /**
     * Determine if the session names a user and was signed in on another credential epoch than the account's.
     */
    protected function hasLeftEpoch(): bool
    {
        if (! $this->namesUnreadUser()) {
            return false;
        }

        $epoch = CredentialEpoch::of($this->session->get($this->getName()));

        return ! $this->state()->isOnEpoch($epoch);
    }

    /**
     * Determine if the session names a user, unless this request already read, set or signed one out.
     */
    protected function namesUnreadUser(): bool
    {
        return ! $this->loggedOut
            && $this->user === null
            && $this->session->has($this->getName());
    }

    /**
     * Determine if the session names no user, unless this request already set or signed one out.
     */
    protected function isGuest(): bool
    {
        return ! $this->loggedOut
            && $this->user === null
            && ! $this->session->has($this->getName());
    }

    /**
     * Sign the browser back in as the account its live remember-me cookie remembers, and determine if it did.
     *
     * A return whose account owes enrollment is refused and its token forgotten, since nothing a session enrolls may rest on an earlier sign-in.
     */
    protected function restore(): bool
    {
        if ($this->recallAttempted || ! $this->session->isStarted()) {
            return false;
        }

        $this->recallAttempted = true;

        $token = $this->container->make(RememberTokens::class)->find();
        $account = $token === null ? null : $this->provider->retrieveById($token->user_id);

        if (! $account instanceof KeystoneUser) {
            return false;
        }

        if ($this->container->make(SignInDecision::class)->isOwed($account, Stage::ENROLLMENT)) {
            $this->refuseOwedReturn($account, $token);

            return false;
        }

        $this->viaRemember = true;

        $this->state()->restore($account, $token);

        $this->recordReturn($account);

        return true;
    }

    /**
     * Record the account's remembered return, and whether it came from a device the account knows.
     */
    protected function recordReturn(KeystoneUser $account): void
    {
        $knownDevice = $this->container->make(KnownDevices::class)->isKnown($account);

        $recorder = $this->container->make(Recorder::class);
        $recorder->record(SecurityEventType::SIGNED_IN, account: $account, reason: 'keystone.remembered', knownDevice: $knownDevice);
    }

    /**
     * Forget the remember-me token of the account that owes enrollment, and send the user to sign in again.
     */
    protected function refuseOwedReturn(KeystoneUser $account, UserRememberToken $token): void
    {
        $this->container->make(RememberTokens::class)->forget($token->getKey());

        $this->state()->leaveStatus(Status::ENROLLMENT_OWED);

        $recorder = $this->container->make(Recorder::class);
        $recorder->record(SecurityEventType::REQUEST_REJECTED, account: $account, reason: 'keystone.enrollment_owed');
    }

    /**
     * Read the user the session names, ending the session instead when its account owes enrollment while a mandate is on.
     */
    protected function readUser(): void
    {
        $account = $this->sessionAccount();

        if ($account === null) {
            return;
        }

        if ($this->container->make(SignInDecision::class)->isOwed($account, Stage::ENROLLMENT)) {
            $this->demote($account);

            return;
        }

        $this->setUser($account);
    }

    /**
     * End the expired session, and have the request that needs a user answered with the app's expiry response.
     */
    protected function expire(): void
    {
        $this->end(Status::SESSION_EXPIRED, reason: 'keystone.expired', account: $this->sessionAccount());

        EndedSession::expired($this->request);
    }

    /**
     * End the session its account's credential epoch left behind.
     */
    protected function endOffEpoch(): void
    {
        $this->end(Status::SESSION_ENDED, reason: 'keystone.epoch', account: $this->sessionAccount());
    }

    /**
     * End the session of the account that newly owes enrollment, and have the request that needs a user answered with the app's demotion response.
     */
    protected function demote(KeystoneUser $account): void
    {
        $this->end(Status::SESSION_DEMOTED, reason: 'keystone.demoted', account: $account);

        EndedSession::demoted($this->request);
    }

    /**
     * End the session, tell the user why on their next page and record it for the account, before anything answers the request.
     */
    protected function end(Status $status, string $reason, ?KeystoneUser $account): void
    {
        $this->loggedOut = true;
        $this->endSession();

        $this->state()->leaveStatus($status);

        $recorder = $this->container->make(Recorder::class);
        $recorder->record(SecurityEventType::SESSION_ENDED, account: $account, reason: $reason);
    }

    /**
     * End the whole session, its remember-me token included, and clear the browser's site data, so nothing it held outlives it.
     */
    protected function endSession(): void
    {
        $token = $this->state()->rememberToken();

        if ($token !== null) {
            $this->container->make(RememberTokens::class)->forget($token);
        }

        $this->session->invalidate();
        $this->session->regenerateToken();

        ClearSiteData::forResponseTo($this->request);
    }

    /**
     * Get the account the session names, if it still exists.
     */
    protected function sessionAccount(): ?KeystoneUser
    {
        $user = $this->provider->retrieveById($this->session->get($this->getName()));

        return $user instanceof KeystoneUser ? $user : null;
    }

    /**
     * Get the session state of the request the guard serves.
     */
    protected function state(): SessionState
    {
        return $this->container->make(SessionState::class);
    }

    /**
     * Read no remember-me cookie of Laravel's, so none restores a session.
     */
    protected function recaller(): null
    {
        return null;
    }
}
