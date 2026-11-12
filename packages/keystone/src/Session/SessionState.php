<?php

namespace ClaudioDekker\Keystone\Session;

use ClaudioDekker\Keystone\Accounts\AccountLookup;
use ClaudioDekker\Keystone\Accounts\CredentialEpoch;
use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Http\RequestContext;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\StartsCeremony;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Models\UserRememberToken;
use ClaudioDekker\Keystone\SecurityEvents\Recorder;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use ClaudioDekker\Keystone\Status;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
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
     * The session key that holds the id of the pending challenge held from the session's browser, which outlives the pending sign-in it was held for.
     */
    protected const string PENDING_CHALLENGE = 'keystone.pending_challenge';

    /**
     * The session key that holds the id of the remember-me token the session issued or restored from.
     */
    protected const string REMEMBER_TOKEN = 'keystone.remember_token';

    /**
     * How long a ceremony lasts after its start, in seconds.
     */
    protected const int CEREMONY_LIFETIME_SECONDS = 10 * 60;

    /**
     * Create the session state over the request's session.
     *
     * The recorder is made from the container when a grant is recorded, since it reads the flow from the session state.
     */
    public function __construct(
        protected AccountLookup $accounts,
        protected Session $session,
        protected UrlGenerator $urls,
        protected Dispatcher $events,
        protected Config $config,
        protected Encrypter $encrypter,
        protected Request $request,
        protected Container $container,
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
     * Hold the sign-in of the account at the stage it owes, in place of whatever phase the session held, keeping whether the user ticked remember me.
     */
    public function hold(KeystoneUser $account, CredentialType $firstFactor, Stage $stage, bool $remember = false): void
    {
        $pending = new PendingSignIn(
            account: $account->getAuthIdentifier(),
            firstFactor: $firstFactor->name(),
            stage: $stage,
            intendedPath: $this->intendedPath(),
            epoch: CredentialEpoch::of($account->getAuthIdentifier()),
            heldAt: Date::now()->getTimestamp(),
            remember: $remember,
        );

        $this->changeAuthLevel();

        $this->session->put(static::PHASE, $pending->toSession());
    }

    /**
     * Move the pending sign-in on to enrollment once the type answered its challenge or was enrolled, keeping its lifetime and intended URL.
     */
    public function passSecondFactor(PendingSignIn $pending, CredentialType $secondFactor): void
    {
        $passed = $pending->passedBy($secondFactor->name());

        $this->changeAuthLevel();

        $this->session->put(static::PHASE, $passed->toSession());
    }

    /**
     * Start the type's ceremony on the surface in place of any earlier one, and get what it kept.
     *
     * A start that throws leaves the surface with no ceremony.
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
     * Get what the page shows of the type's live ceremony on the surface for the account, or null when none is live.
     *
     * @return array<string, mixed>|null
     */
    public function ceremonyPage(CredentialType&StartsCeremony $type, Surface $surface, string $account): ?array
    {
        $ceremony = $this->ceremony($type, $surface);

        if ($ceremony === null) {
            return null;
        }

        return $type->page($surface, $ceremony, $account);
    }

    /**
     * Get the session's pending sign-in, unless it has none or it outlived its lifetime.
     */
    public function pendingSignIn(): ?PendingSignIn
    {
        $pending = PendingSignIn::fromSession($this->phase());

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
     * Get the id of the pending challenge held from the session's browser, if any.
     */
    public function pendingChallenge(): int|string|null
    {
        $id = $this->session->get(static::PENDING_CHALLENGE);

        return is_int($id) || is_string($id) ? $id : null;
    }

    /**
     * Remember the id of the pending challenge held from the session's browser.
     */
    public function keepPendingChallenge(int|string $id): void
    {
        $this->session->put(static::PENDING_CHALLENGE, $id);
    }

    /**
     * Forget the pending challenge held from the session's browser.
     */
    public function forgetPendingChallenge(): void
    {
        $this->session->forget(static::PENDING_CHALLENGE);
    }

    /**
     * Drop the session's pending sign-in, leaving a guest.
     */
    public function dropPendingSignIn(): void
    {
        $this->changeAuthLevel();
    }

    /**
     * Complete a sign-in for the account, granting it sudo, and get the path to send the user to, the one its pending sign-in kept if it has one.
     */
    public function signIn(KeystoneUser $account): string
    {
        $redirectTo = $this->pendingSignIn()->intendedPath ?? $this->intendedPath();

        $this->changeAuthLevel();
        $this->enter($account);
        $this->grantSudoForSignIn($account);

        return $redirectTo;
    }

    /**
     * Get the session's sudo grant while it is live, unless it has none.
     */
    public function liveSudoGrant(): ?SudoGrant
    {
        $grant = SudoGrant::fromSession($this->phase());
        $lifetime = $this->config->integer('keystone.sudo.lifetime_seconds');

        if ($grant?->isLiveAt(Date::now()->getTimestamp(), $lifetime) !== true) {
            return null;
        }

        return $grant;
    }

    /**
     * Get the session's sudo-in-progress, unless it has none or it outlived its lifetime.
     */
    public function sudoInProgress(): ?SudoInProgress
    {
        $sudo = SudoInProgress::fromSession($this->phase());

        if ($sudo?->hasExpiredAt(Date::now()->getTimestamp()) !== false) {
            return null;
        }

        return $sudo;
    }

    /**
     * Move the sudo-in-progress on to its second step once the type passed its first, keeping its start, on a fresh session id.
     */
    public function passSudoFirstFactor(SudoInProgress $sudo, CredentialType $firstFactor): void
    {
        $passed = $sudo->passedBy($firstFactor->name());

        $this->changeAuthLevel();

        $this->session->put(static::PHASE, $passed->toSession());
    }

    /**
     * Grant the session sudo bound to the subnet, in place of its sudo-in-progress, on a fresh session id.
     */
    public function grantSudo(string $subnet): void
    {
        $this->changeAuthLevel();
        $this->putSudoGrant($subnet);
    }

    /**
     * End the session's sudo grant and sudo-in-progress on a fresh session id, and determine if it held a live grant.
     */
    public function endSudo(): bool
    {
        $wasGranted = $this->liveSudoGrant() !== null;

        $this->changeAuthLevel();

        return $wasGranted;
    }

    /**
     * Get the subnet of the request's address the session's sudo grant is bound to, or null when the address has none.
     */
    public function subnet(): ?string
    {
        return RequestContext::of($this->request)?->subnet;
    }

    /**
     * Refuse the session sudo, starting a sudo-in-progress that goes on to the path in place of whatever phase it held.
     *
     * A live sudo-in-progress keeps its start, and goes on to the path only when it replaces the one it kept.
     */
    public function refuseSudo(string $intendedPath, bool $replacesIntendedPath): void
    {
        $sudo = $this->sudoInProgress();

        if ($sudo !== null && ! $replacesIntendedPath) {
            return;
        }

        if ($sudo !== null) {
            $this->session->put(static::PHASE, $sudo->goingTo($intendedPath)->toSession());

            return;
        }

        $sudo = new SudoInProgress(
            intendedPath: $intendedPath,
            epoch: $this->epoch(),
            startedAt: Date::now()->getTimestamp(),
        );

        $this->session->forget(static::CEREMONIES);
        $this->session->put(static::PHASE, $sudo->toSession());
    }

    /**
     * Restore the full sign-in of the account the remember-me token remembers, on a fresh session id.
     */
    public function restore(KeystoneUser $account, UserRememberToken $token): void
    {
        $this->changeAuthLevel();
        $this->enter($account, remember: true);
        $this->keepRememberToken($token);
    }

    /**
     * Remember the remember-me token the session issued or restored from, so ending the session forgets it.
     */
    public function keepRememberToken(UserRememberToken $token): void
    {
        $this->session->put(static::REMEMBER_TOKEN, $token->getKey());
    }

    /**
     * Get the account's credential epoch the session's sign-in was stamped with, if any.
     */
    public function epoch(): ?int
    {
        $epoch = $this->session->get(static::EPOCH);

        return is_int($epoch) ? $epoch : null;
    }

    /**
     * Get the id of the remember-me token the session issued or restored from, if any.
     */
    public function rememberToken(): int|string|null
    {
        $id = $this->session->get(static::REMEMBER_TOKEN);

        return is_int($id) || is_string($id) ? $id : null;
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
     * Take the intended URL out of the session as a path on the app, or the root when it isn't one.
     */
    public function intendedPath(): string
    {
        $intended = $this->session->pull('url.intended');

        if (! is_string($intended)) {
            return '/';
        }

        return $this->toPath($intended) ?? '/';
    }

    /**
     * Get the URL as a path on the app, or null when it isn't one.
     */
    public function toPath(string $url): ?string
    {
        $path = Str::chopStart($url, $this->urls->to('/'));

        if (! $this->isPath($path)) {
            return null;
        }

        return $path;
    }

    /**
     * Get the flow a step on the surface is in, or null when it is in none, such as signing out.
     *
     * A signed-in session whose sudo-in-progress is at the surface is in the sudo flow.
     */
    public function flow(?Surface $surface): ?Flow
    {
        if ($surface !== null
            && $this->sudoInProgress()?->surface() === $surface
            && $this->isSignedIn()
        ) {
            return Flow::SUDO;
        }

        return match ($surface) {
            Surface::CHALLENGE => Flow::CHALLENGE,
            Surface::ENROLLMENT => Flow::ENROLLMENT,
            Surface::SIGN_IN => Flow::SIGN_IN,
            default => null,
        };
    }

    /**
     * Grant the session sudo bound to the request's subnet once its sign-in completed, recording the grant, unless the request's address has no subnet.
     */
    protected function grantSudoForSignIn(KeystoneUser $account): void
    {
        $subnet = $this->subnet();

        if ($subnet === null) {
            return;
        }

        $this->putSudoGrant($subnet);

        $recorder = $this->container->make(Recorder::class);
        $recorder->record(SecurityEventType::SUDO_GRANTED, account: $account, reason: 'keystone.sign_in');
    }

    /**
     * Put a sudo grant bound to the subnet in the session's phase, granted now.
     */
    protected function putSudoGrant(string $subnet): void
    {
        $grant = new SudoGrant(
            grantedAt: Date::now()->getTimestamp(),
            subnet: $subnet,
        );

        $this->session->put(static::PHASE, $grant->toSession());
    }

    /**
     * Get the session's phase as its session value, empty when it holds none.
     *
     * @return array<array-key, mixed>
     */
    protected function phase(): array
    {
        $phase = $this->session->get(static::PHASE);

        return is_array($phase) ? $phase : [];
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
     * Get the Unix second a ceremony started now ends at: its lifetime after now, or the end of the pending sign-in or sudo-in-progress it was started under when that comes first.
     */
    protected function ceremonyEndsAt(): int
    {
        $endsAt = Date::now()->getTimestamp() + static::CEREMONY_LIFETIME_SECONDS;
        $phaseEndsAt = $this->pendingSignIn()?->endsAt() ?? $this->sudoInProgress()?->endsAt() ?? $endsAt;

        return min($endsAt, $phaseEndsAt);
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
    protected function enter(KeystoneUser $account, bool $remember = false): void
    {
        $guard = Keystone::guard();

        $this->session->put($guard->getName(), $account->getAuthIdentifier());
        $this->session->put(static::SIGNED_IN_AT, Date::now()->getTimestamp());
        $this->session->put(static::EPOCH, CredentialEpoch::of($account->getAuthIdentifier()));
        $guard->setUser($account);

        $this->events->dispatch(new Login($guard->getName(), $account, $remember));
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
