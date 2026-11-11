<?php

namespace ClaudioDekker\Keystone\Session;

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
        protected Config $config,
        protected CookieJar $cookies,
        protected Request $request,
    ) {
        //
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
     * Get the token of the browser's remember-me cookie, if its lifetime has yet to pass and its account is still on the epoch it was issued on.
     */
    public function find(): ?UserRememberToken
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

        return $token;
    }

    /**
     * Delete the token with the id and have the browser drop its cookie.
     */
    public function forget(int|string $id): void
    {
        UserRememberToken::query()->whereKey($id)->delete();

        $this->cookies->queue($this->makeCookie('', expire: 1));
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
