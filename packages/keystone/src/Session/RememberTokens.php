<?php

namespace ClaudioDekker\Keystone\Session;

use ClaudioDekker\Keystone\Accounts\AccountLookup;
use ClaudioDekker\Keystone\Accounts\CredentialEpoch;
use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Models\UserRememberToken;
use ClaudioDekker\Keystone\SecurityEvents\Recorder;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Cookie\QueueingFactory as CookieJar;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * @internal
 */
class RememberTokens
{
    /**
     * Create the remember-me tokens.
     */
    public function __construct(
        protected AccountLookup $accounts,
        protected Config $config,
        protected CookieJar $cookies,
        protected Recorder $recorder,
        protected Request $request,
    ) {
        //
    }

    /**
     * Determine if remember me is on, which a lifetime of zero turns off.
     */
    public function isOn(): bool
    {
        return $this->config->integer('keystone.remember.lifetime_seconds') > 0;
    }

    /**
     * Hand the browser signing in to the account a remember-me cookie for the lifetime, on the account's credential epoch, and get its token.
     */
    public function issue(KeystoneUser $account): UserRememberToken
    {
        $cookie = RememberCookie::generate();
        $lifetime = $this->config->integer('keystone.remember.lifetime_seconds');

        $token = UserRememberToken::query()->forceCreate([
            'user_id' => $account->getAuthIdentifier(),
            'digest' => $cookie->digest(),
            'epoch' => CredentialEpoch::of($account->getAuthIdentifier()),
            'expires_at' => Date::now()->addSeconds($lifetime),
        ]);

        $this->cookies->queue($this->makeCookie($cookie->value(), $token->expires_at->getTimestamp()));

        return $token;
    }

    /**
     * Get the token of the browser's remember-me cookie while it is live, forgetting a dead one and recording its refusal.
     *
     * While remember me is off the cookie is not read, so a cookie issued earlier is left alone.
     */
    public function find(): ?UserRememberToken
    {
        $sent = $this->sentCookie();

        if ($sent === null || ! $this->isOn()) {
            return null;
        }

        $token = UserRememberToken::query()->where('digest', $sent->digest())->first();

        if ($token === null || ! $this->isLive($token)) {
            $this->refuseDead($token);

            return null;
        }

        return $token;
    }

    /**
     * Delete the token with the id and have the browser drop its cookie.
     */
    public function forget(int|string $id): void
    {
        UserRememberToken::query()->whereKey($id)->delete();

        $this->forgetCookie();
    }

    /**
     * Determine if the token's lifetime has yet to pass and its account is still on the credential epoch it was issued on, which a barred account never is.
     */
    protected function isLive(UserRememberToken $token): bool
    {
        return $token->expires_at->isFuture()
            && CredentialEpoch::of($token->user_id) === $token->epoch;
    }

    /**
     * Have the browser drop its dead cookie, forget the token it matched, if any, and record the refusal against that token's account.
     */
    protected function refuseDead(?UserRememberToken $token): void
    {
        $token?->delete();

        $this->forgetCookie();

        $this->recorder->record(
            SecurityEventType::REQUEST_REJECTED,
            account: $token === null ? null : $this->accounts->find($token->user_id),
            reason: 'keystone.dead_remember_cookie',
        );
    }

    /**
     * Have the browser drop its remember-me cookie.
     */
    protected function forgetCookie(): void
    {
        $this->cookies->queue($this->makeCookie('', expire: 1));
    }

    /**
     * Delete every token whose lifetime has passed, and get how many.
     */
    public function prune(): int
    {
        return UserRememberToken::query()
            ->where('expires_at', '<=', Date::now())
            ->delete();
    }

    /**
     * Get the remember-me cookie the browser sent, or null when it sent none Keystone could have handed out.
     */
    protected function sentCookie(): ?RememberCookie
    {
        $value = $this->request->cookie(RememberCookie::NAME);

        return RememberCookie::fromValue(is_string($value) ? $value : null);
    }

    /**
     * Make the cookie with the value, which the browser keeps until the Unix second, out of reach of scripts and other sites.
     */
    protected function makeCookie(string $value, int $expire): Cookie
    {
        return new Cookie(
            name: RememberCookie::NAME,
            value: $value,
            expire: $expire,
            path: '/',
            domain: null,
            secure: true,
            httpOnly: true,
            raw: false,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }
}
