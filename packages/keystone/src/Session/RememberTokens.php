<?php

namespace ClaudioDekker\Keystone\Session;

use Carbon\CarbonImmutable;
use ClaudioDekker\Keystone\Accounts\AccountLookup;
use ClaudioDekker\Keystone\Accounts\CredentialEpoch;
use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Models\UserRememberToken;
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
        protected Request $request,
    ) {
        //
    }

    /**
     * Hand the browser signing in to the account a remember-me cookie for the lifetime, on the account's credential epoch.
     */
    public function issue(KeystoneUser $account): void
    {
        $cookie = RememberCookie::generate();
        $lifetime = $this->config->integer('keystone.remember.lifetime_seconds');

        $token = UserRememberToken::query()->forceCreate([
            'user_id' => $account->getAuthIdentifier(),
            'digest' => $cookie->digest(),
            'epoch' => CredentialEpoch::of($account->getAuthIdentifier()),
            'expires_at' => Date::now()->addSeconds($lifetime),
        ]);

        $this->cookies->queue($this->makeCookie($cookie, $token->expires_at));
    }

    /**
     * Get the account the browser's remember-me cookie remembers, if its lifetime has yet to pass and the account is still on the epoch it was issued on.
     */
    public function account(): ?KeystoneUser
    {
        $sent = $this->sentCookie();

        if ($sent === null) {
            return null;
        }

        $token = UserRememberToken::query()
            ->where('digest', $sent->digest())
            ->where('expires_at', '>', Date::now())
            ->first();

        if ($token === null || CredentialEpoch::of($token->user_id) !== $token->epoch) {
            return null;
        }

        return $this->accounts->find($token->user_id);
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
     * Make the cookie the browser keeps until its token expires, out of reach of scripts and other sites.
     */
    protected function makeCookie(RememberCookie $cookie, CarbonImmutable $expiresAt): Cookie
    {
        return new Cookie(
            name: RememberCookie::NAME,
            value: $cookie->value(),
            expire: $expiresAt->getTimestamp(),
            path: '/',
            domain: null,
            secure: true,
            httpOnly: true,
            raw: false,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }
}
