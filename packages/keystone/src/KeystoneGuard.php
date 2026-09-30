<?php

namespace ClaudioDekker\Keystone;

use Carbon\CarbonInterface;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use LogicException;

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

        $this->fireAuthenticatedEvent($this->user = $user);

        return $this->user;
    }

    /**
     * Start a signed-in session for the user, stamped with the credential epoch they were read with.
     */
    public function signIn(Model&KeystoneUser $user): void
    {
        $account = $this->retrieveAccount($user->getAuthIdentifier());

        if (is_null($account)) {
            throw new LogicException('The account being signed in no longer exists.');
        }

        if (! $this->isActive($account)) {
            throw new LogicException('The account being signed in is disabled or suspended.');
        }

        $this->rotate();

        $this->session->put($this->getName(), $user->getAuthIdentifier());
        $this->session->put($this->epochKey(), $this->epochOf($user));
        $this->session->put($this->signedInAtKey(), Date::now()->getTimestamp());

        $this->fireLoginEvent($user);

        $this->setUser($user);
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
     * Get the session key holding the time the session signed in.
     */
    protected function signedInAtKey(): string
    {
        return 'keystone_signed_in_at_'.$this->name;
    }
}
