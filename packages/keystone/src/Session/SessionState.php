<?php

namespace ClaudioDekker\Keystone\Session;

use ClaudioDekker\Keystone\Accounts\AccountLookup;
use ClaudioDekker\Keystone\Accounts\CredentialEpoch;
use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\StartsCeremony;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Status;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Date;
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
     * The session key that holds when the session's sign-in completed, in Unix seconds.
     */
    protected const string SIGNED_IN_AT = 'keystone.signed_in_at';

    /**
     * The session key that holds the account's credential epoch at the session's sign-in.
     */
    protected const string EPOCH = 'keystone.epoch';

    /**
     * The session key that holds the session's one phase, such as a pending sign-in.
     */
    protected const string PHASE = 'keystone.phase';

    /**
     * The session key that holds the ceremony slots, keyed by surface and credential type.
     */
    protected const string CEREMONIES = 'keystone.ceremonies';

    /**
     * How long a ceremony lasts after its start, in seconds.
     */
    protected const int CEREMONY_LIFETIME_SECONDS = 10 * 60;

    /**
     * Create the session state over the request's session.
     */
    public function __construct(
        protected AccountLookup $accounts,
        protected Session $session,
        protected UrlGenerator $urls,
        protected Dispatcher $events,
        protected Config $config,
        protected Encrypter $encrypter,
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
     * Determine if the session's sign-in is past the absolute lifetime, a missing or future sign-in time included.
     */
    public function hasExpired(): bool
    {
        $lifetime = $this->config->get('keystone.session.absolute_lifetime_seconds');

        if ($lifetime === null) {
            return false;
        }

        $signedInAt = $this->session->get(static::SIGNED_IN_AT);
        $now = Date::now()->getTimestamp();

        return ! is_int($signedInAt)
            || $signedInAt > $now
            || $now >= $signedInAt + $lifetime;
    }

    /**
     * Determine if the session was signed in on the epoch.
     */
    public function isOnEpoch(?int $epoch): bool
    {
        return $this->session->get(static::EPOCH) === $epoch;
    }

    /**
     * Leave the status for the next page to show.
     */
    public function leaveStatus(Status $status): void
    {
        $this->session->flash(static::STATUS, $status->value);
    }

    /**
     * Hold the sign-in of the account at the stage it owes, in place of whatever phase the session held.
     */
    public function hold(KeystoneUser $account, CredentialType $firstFactor, Stage $stage): void
    {
        $pending = new PendingSignIn(
            account: $account->getAuthIdentifier(),
            firstFactor: $firstFactor->name(),
            stage: $stage,
            intendedPath: $this->intendedPath(),
            epoch: CredentialEpoch::of($account->getAuthIdentifier()),
            heldAt: Date::now()->getTimestamp(),
        );

        $this->changeAuthLevel();

        $this->session->put(static::PHASE, $pending->toSession());
    }

    /**
     * Start the type's ceremony on the surface, keeping what the start returned in its slot in place of any earlier one, and get it.
     *
     * The earlier ceremony ends before the type starts, so a start that throws leaves none.
     *
     * @return array<string, mixed>|null
     */
    public function startCeremony(CredentialType&StartsCeremony $type, Surface $surface): ?array
    {
        $this->forgetCeremony($type, $surface);

        $ceremony = $type->start($surface);

        $slot = $this->encrypter->encrypt([
            'ceremony' => $ceremony,
            'ends_at' => $this->ceremonyEndsAt(),
        ]);

        $this->session->put(static::CEREMONIES, [
            ...$this->session->get(static::CEREMONIES, []),
            $this->slot($type, $surface) => $slot,
        ]);

        return $ceremony;
    }

    /**
     * Get what the type's live ceremony on the surface kept, or null when none is live.
     *
     * @return array<string, mixed>|null
     */
    public function ceremony(CredentialType $type, Surface $surface): ?array
    {
        $encrypted = $this->session->get(static::CEREMONIES)[$this->slot($type, $surface)] ?? null;

        if ($encrypted === null) {
            return null;
        }

        try {
            $slot = $this->encrypter->decrypt($encrypted);
        } catch (DecryptException) {
            return null;
        }

        if (Date::now()->getTimestamp() >= $slot['ends_at']) {
            return null;
        }

        return $slot['ceremony'];
    }

    /**
     * Get the session's pending sign-in, unless it has none or it outlived its lifetime.
     */
    public function pendingSignIn(): ?PendingSignIn
    {
        $phase = $this->session->get(static::PHASE);

        if (! is_array($phase)) {
            return null;
        }

        $pending = PendingSignIn::fromSession($phase);

        if ($pending?->hasExpiredAt(Date::now()->getTimestamp()) !== false) {
            return null;
        }

        return $pending;
    }

    /**
     * Get the account the session's pending sign-in holds, if any.
     */
    public function heldAccount(): ?KeystoneUser
    {
        $pending = $this->pendingSignIn();

        if ($pending === null) {
            return null;
        }

        return $this->accounts->find($pending->account);
    }

    /**
     * Drop the session's pending sign-in, leaving a guest.
     */
    public function dropPendingSignIn(): void
    {
        $this->changeAuthLevel();
    }

    /**
     * Complete a sign-in for the account, and get the path to send the user to, the one its pending sign-in kept if it has one.
     */
    public function signIn(KeystoneUser $account): string
    {
        $redirectTo = $this->pendingSignIn()->intendedPath ?? $this->intendedPath();

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

        $this->leaveStatus(Status::SIGNED_OUT);

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
            Surface::CHALLENGE => Flow::CHALLENGE,
            Surface::SIGN_IN => Flow::SIGN_IN,
            default => null,
        };
    }

    /**
     * Give the session a new id and CSRF token and forget its phase and ceremonies, so nothing that knew the old id, phase or ceremony reaches the new level.
     */
    protected function changeAuthLevel(): void
    {
        $this->session->regenerate(true);
        $this->session->forget([static::PHASE, static::CEREMONIES]);
    }

    /**
     * End the type's ceremony on the surface.
     */
    protected function forgetCeremony(CredentialType $type, Surface $surface): void
    {
        $ceremonies = $this->session->get(static::CEREMONIES, []);

        unset($ceremonies[$this->slot($type, $surface)]);

        $this->session->put(static::CEREMONIES, $ceremonies);
    }

    /**
     * Get the Unix second a ceremony started now ends at: its lifetime after now, or the end of the pending sign-in it was started under when that comes first.
     */
    protected function ceremonyEndsAt(): int
    {
        $endsAt = Date::now()->getTimestamp() + static::CEREMONY_LIFETIME_SECONDS;

        return min($endsAt, $this->pendingSignIn()?->endsAt() ?? $endsAt);
    }

    /**
     * Get the key of the type's ceremony slot on the surface.
     */
    protected function slot(CredentialType $type, Surface $surface): string
    {
        return $surface->value.':'.$type->name();
    }

    /**
     * Stamp the sign-in time and the account's credential epoch, and put the account on the guard's session key and on the guard for the rest of the request.
     */
    protected function enter(KeystoneUser $account): void
    {
        $guard = Keystone::guard();

        $this->session->put($guard->getName(), $account->getAuthIdentifier());
        $this->session->put(static::SIGNED_IN_AT, Date::now()->getTimestamp());
        $this->session->put(static::EPOCH, CredentialEpoch::of($account->getAuthIdentifier()));
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
