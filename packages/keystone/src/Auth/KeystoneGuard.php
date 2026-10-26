<?php

namespace ClaudioDekker\Keystone\Auth;

use ClaudioDekker\Keystone\Exceptions\SessionGuardSignInException;
use ClaudioDekker\Keystone\Http\Middleware\ClearSiteData;
use ClaudioDekker\Keystone\Http\SessionExpiry;
use ClaudioDekker\Keystone\SecurityEvents\Recorder;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use ClaudioDekker\Keystone\Session\SessionState;
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
     * Get the signed-in user, ending a session past its absolute lifetime first so the request reads it as a guest's.
     */
    public function user(): ?Authenticatable
    {
        if ($this->hasExpired()) {
            $this->expire();
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
     * Determine if the session names a user whose sign-in is past the absolute lifetime, unless this request already read or set one.
     */
    protected function hasExpired(): bool
    {
        return ! $this->loggedOut
            && $this->user === null
            && $this->session->has($this->getName())
            && $this->state()->hasExpired();
    }

    /**
     * End the expired session and tell the user why on their next page, before anything answers the request.
     */
    protected function expire(): void
    {
        $account = $this->sessionAccount();

        $this->loggedOut = true;
        $this->endSession();

        $this->state()->leaveStatus(Status::SESSION_EXPIRED);
        SessionExpiry::forResponseTo($this->request);

        $recorder = $this->container->make(Recorder::class);
        $recorder->record(SecurityEventType::SESSION_ENDED, account: $account, reason: 'keystone.expired');
    }

    /**
     * End the whole session and clear the browser's site data, so nothing it held outlives it.
     */
    protected function endSession(): void
    {
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
