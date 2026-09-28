<?php

namespace ClaudioDekker\Keystone;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @internal
 *
 * @property EloquentUserProvider $provider
 */
class KeystoneGuard extends SessionGuard
{
    /**
     * The columns that end a session when set.
     *
     * @var list<string>
     */
    protected const array ENDING_COLUMNS = ['deleted_at', 'invalidated_at', 'suspended_at'];

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
     * Start a signed-in session for the user, stamped with their credential epoch.
     */
    public function signIn(KeystoneUser $user): void
    {
        $this->rotate();

        $this->session->put($this->getName(), $user->getAuthIdentifier());
        $this->session->put($this->epochKey(), $this->currentEpoch($user));

        $this->setUser($user);
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
     * Read the account and its Keystone state in one query, bypassing every global scope.
     *
     * @return (Model&KeystoneUser)|null
     */
    protected function retrieveAccount(mixed $id): ?Model
    {
        /** @var (Model&KeystoneUser)|null */
        return $this->provider->createModel()->newModelQuery()->whereKey($id)->first();
    }

    /**
     * Determine if the account is active and still on the session's credential epoch.
     */
    protected function isLive(Model&KeystoneUser $account): bool
    {
        foreach (self::ENDING_COLUMNS as $column) {
            if (! is_null($account->getRawOriginal($column))) {
                return false;
            }
        }

        return $this->session->get($this->epochKey()) === $this->epochOf($account);
    }

    /**
     * Read the account's current credential epoch from the database.
     */
    protected function currentEpoch(KeystoneUser $user): int
    {
        $account = $this->retrieveAccount($user->getAuthIdentifier());

        if (is_null($account)) {
            throw new LogicException('The account being signed in no longer exists.');
        }

        return $this->epochOf($account);
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
}
