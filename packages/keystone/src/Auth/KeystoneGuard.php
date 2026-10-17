<?php

namespace ClaudioDekker\Keystone\Auth;

use ClaudioDekker\Keystone\Exceptions\SessionGuardSignInException;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use SensitiveParameter;

/**
 * Resolve the signed-in account from the session, and sign nobody in through any SessionGuard method.
 *
 * Only Keystone signs in, so no sign-in skips its checks. once() and onceBasic() are refused through validate(), and basic() through attempt().
 *
 * @internal
 */
class KeystoneGuard extends SessionGuard
{
    /**
     * Refuse to sign anyone in with credentials.
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
     * Sign the user out and end the whole session, so nothing it held outlives them, Auth::logout() included.
     */
    public function logout(): void
    {
        parent::logout();

        $this->session->invalidate();
        $this->session->regenerateToken();
    }

    /**
     * Read no remember-me cookie of Laravel's, so none restores a session.
     */
    protected function recaller(): null
    {
        return null;
    }
}
