<?php

namespace ClaudioDekker\Keystone\Session;

use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Status;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;

/**
 * @internal
 */
class SessionState
{
    /**
     * The session key that holds the status the next page shows.
     */
    protected const string STATUS = 'keystone.status';

    /**
     * Create the session state over the request's session.
     */
    public function __construct(
        protected Session $session,
        protected UrlGenerator $urls,
        protected Dispatcher $events,
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
     * Get the signed-in account, if any.
     */
    public function account(): ?KeystoneUser
    {
        $user = Keystone::guard()->user();

        return $user instanceof KeystoneUser ? $user : null;
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
     * End the session and leave the signed-out status for the sign-in page, and get who was signed in, if anyone.
     */
    public function signOut(): ?KeystoneUser
    {
        $account = $this->account();

        Keystone::guard()->logout();

        $this->session->flash(static::STATUS, Status::SIGNED_OUT->value);

        return $account;
    }

    /**
     * Get the status the previous request left for this page.
     */
    public function status(): ?Status
    {
        return Status::tryFrom($this->session->get(static::STATUS, ''));
    }

    /**
     * Get the flow a step on the surface is in, or null when it is in none, such as signing out.
     */
    public function flow(?Surface $surface): ?Flow
    {
        return match ($surface) {
            Surface::SIGN_IN => Flow::SIGN_IN,
            default => null,
        };
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

        $this->events->dispatch(new Login($guard->getName(), $account, false));
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
