<?php

namespace ClaudioDekker\Keystone\Session;

use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Keystone;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;

/**
 * Own Keystone's side of the session: who is signed in.
 *
 * Every change of auth level goes through here, so the session rotates in one place. Controllers never touch the session or the guard.
 *
 * @internal
 */
class SessionState
{
    /**
     * Create the session state over the request's session.
     */
    public function __construct(
        protected Session $session,
        protected UrlGenerator $urls,
    ) {
        //
    }

    /**
     * Determine if the session is signed in.
     */
    public function isSignedIn(): bool
    {
        return Keystone::guard()->check();
    }

    /**
     * Complete a sign-in for the account, and get the path to send the user to.
     */
    public function signIn(KeystoneUser $account): string
    {
        $redirectTo = $this->intendedPath();

        $this->changeAuthLevel();
        $this->enter($account);

        return $redirectTo;
    }

    /**
     * Give the session a new id and CSRF token, so nothing that knew the old id reaches the new level.
     */
    protected function changeAuthLevel(): void
    {
        $this->session->regenerate(true);
    }

    /**
     * Put the account on the guard's session key and on the guard for the rest of the request.
     */
    protected function enter(KeystoneUser $account): void
    {
        $guard = Keystone::guard();

        $this->session->put($guard->getName(), $account->getAuthIdentifier());
        $guard->setUser($account);
    }

    /**
     * Take the intended URL out of the session as a path on the app, or the root when it isn't one.
     */
    protected function intendedPath(): string
    {
        $intended = $this->session->pull('url.intended');

        if (! is_string($intended)) {
            return '/';
        }

        $path = Str::chopStart($intended, $this->urls->to('/'));

        if (! $this->isPath($path)) {
            return '/';
        }

        return $path;
    }

    /**
     * Determine if the URL is a path on this app: one leading slash, and no backslash or control character.
     */
    protected function isPath(string $url): bool
    {
        return str_starts_with($url, '/')
            && ! str_starts_with($url, '//')
            && ! str_contains($url, '\\')
            && preg_match('/[\x00-\x1F\x7F]/', $url) === 0;
    }
}
