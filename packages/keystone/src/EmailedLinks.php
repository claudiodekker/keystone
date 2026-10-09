<?php

namespace ClaudioDekker\Keystone;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Uri;
use JsonException;

/**
 * @internal
 */
class EmailedLinks
{
    /**
     * How long every emailed link works from its issue.
     */
    public const int LIFETIME_SECONDS = 600;

    /**
     * The table holding a digest of every spent link until it expires.
     */
    public const string TABLE = 'used_email_links';

    /**
     * Create a new emailed links instance.
     */
    public function __construct(
        protected KeystoneGuard $guard,
        protected SecurityEventRecorder $recorder = new SecurityEventRecorder,
    ) {
        //
    }

    /**
     * Build the link's URL on app.url, its path included, never on the request's host, signed and encrypted under the current app key alone.
     */
    public function issue(EmailedLink $link): IssuedLink
    {
        $expiresAt = Date::now()->toImmutable()->startOfSecond()->addSeconds(self::LIFETIME_SECONDS);
        $expires = (string) $expiresAt->getTimestamp();
        $path = route($link::route(), absolute: false);
        $token = $this->encrypter($link::purpose())->encryptString(json_encode($link->payload(), JSON_THROW_ON_ERROR));
        $signature = $this->sign($link::purpose(), $path, $expires, $token);
        $url = Uri::of($this->base().$path)->withQuery(['expires' => $expires, 'token' => $token, 'signature' => $signature]);

        return new IssuedLink((string) $url, $expiresAt);
    }

    /**
     * Mail the address a new link while the link's destination qualifies, or let the kind act on the destination it lost instead.
     */
    public function mail(EmailedLink $link, string $address): void
    {
        if (! $link->destinationHolds($this->guard)) {
            $link->destinationLost($this->guard);

            return;
        }

        (new AnonymousNotifiable)->route('mail', $address)->notify($link->mail($this->issue($link)));
    }

    /**
     * Read the link the request opened without spending it, or record why it was refused.
     *
     * @template TLink of EmailedLink
     *
     * @param  class-string<TLink>  $kind
     * @return TLink|null
     */
    public function open(Request $request, string $kind): ?EmailedLink
    {
        return $this->read($request, $kind)?->link;
    }

    /**
     * Spend the link the request carries and hand it back while its destination still qualifies, or record why it was refused.
     *
     * @template TLink of EmailedLink
     *
     * @param  class-string<TLink>  $kind
     * @return TLink|null
     */
    public function consume(Request $request, string $kind): ?EmailedLink
    {
        $verified = $this->read($request, $kind);

        if ($verified === null) {
            return null;
        }

        if (! $this->spend($verified)) {
            return $this->reject($verified->link, 'keystone.link_used');
        }

        if (! $verified->link->destinationHolds($this->guard)) {
            $verified->link->destinationLost($this->guard);

            return $this->reject($verified->link, 'keystone.link_destination');
        }

        return $verified->link;
    }

    /**
     * Delete the digests of the spent links that have expired, which no link can be checked against again.
     */
    public static function prune(Model&KeystoneUser $users): void
    {
        $users->getConnection()->table(self::TABLE)->where('expires_at', '<=', Date::now())->delete();
    }

    /**
     * Verify the link the request carries, recording a refusal when it no longer holds.
     *
     * @template TLink of EmailedLink
     *
     * @param  class-string<TLink>  $kind
     * @return VerifiedLink<TLink>|null
     */
    protected function read(Request $request, string $kind): ?VerifiedLink
    {
        $verified = $this->verify($request, $kind);

        if ($verified === null) {
            $this->reject(null, 'keystone.link_invalid');
        }

        return $verified;
    }

    /**
     * Check the request's host, expiry and signature, then decrypt its payload into the kind.
     *
     * @template TLink of EmailedLink
     *
     * @param  class-string<TLink>  $kind
     * @return VerifiedLink<TLink>|null
     */
    protected function verify(Request $request, string $kind): ?VerifiedLink
    {
        if ($request->root() !== $this->base()) {
            return null;
        }

        $expires = $request->query('expires');
        $token = $request->query('token');
        $signature = $request->query('signature');

        if (! is_string($expires) || ! is_string($token) || ! is_string($signature)) {
            return null;
        }

        $expiresAt = $this->expiry($expires);
        $path = route($kind::route(), absolute: false);

        if ($expiresAt === null || ! hash_equals($this->sign($kind::purpose(), $path, $expires, $token), $signature)) {
            return null;
        }

        $link = $kind::fromPayload($this->decrypt($kind::purpose(), $token));

        return $link === null ? null : new VerifiedLink($link, $signature, $expiresAt);
    }

    /**
     * Get the time the link expires at, in the app's timezone, or null once it has, or when it claims to outlive a fresh link.
     */
    protected function expiry(string $expires): ?CarbonImmutable
    {
        if (! ctype_digit($expires)) {
            return null;
        }

        $now = Date::now()->toImmutable();
        $timestamp = (int) $expires;

        if ($timestamp <= $now->getTimestamp() || $timestamp > $now->getTimestamp() + self::LIFETIME_SECONDS) {
            return null;
        }

        return $now->setTimestamp($timestamp);
    }

    /**
     * Decrypt the token under the purpose's subkey and decode its JSON, getting no payload for anything else.
     *
     * @return array<mixed>
     */
    protected function decrypt(string $purpose, string $token): array
    {
        try {
            $payload = json_decode($this->encrypter($purpose)->decryptString($token), true, 4, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return [];
        }

        return is_array($payload) ? $payload : [];
    }

    /**
     * Store the link's digest until it expires, answering false when another request stored it first.
     *
     * @param  VerifiedLink<EmailedLink>  $verified
     */
    protected function spend(VerifiedLink $verified): bool
    {
        $stored = $this->guard->userModel()->getConnection()->table(self::TABLE)->insertOrIgnore([
            'digest' => Hmac::make('keystone.emailed-link.digest', $verified->signature),
            'expires_at' => $verified->expiresAt,
        ]);

        return $stored === 1;
    }

    /**
     * Record the refused link against the account it names, if any.
     */
    protected function reject(?EmailedLink $link, string $reason): null
    {
        $this->recorder->record(SecurityEventType::REQUEST_REJECTED, account: $link?->account($this->guard), reason: $reason);

        return null;
    }

    /**
     * Get the signature binding the token to the purpose, app.url, the route and the expiry.
     */
    protected function sign(string $purpose, string $path, string $expires, string $token): string
    {
        return Hmac::make("keystone.emailed-link.sign.{$purpose}", json_encode([$this->base(), $path, $expires, $token], JSON_THROW_ON_ERROR));
    }

    /**
     * Get an encrypter holding only the purpose's subkey of the current app key, so no previous key ever opens a payload.
     */
    protected function encrypter(string $purpose): Encrypter
    {
        return new Encrypter(Hmac::subkey("keystone.emailed-link.payload.{$purpose}"), 'aes-256-gcm');
    }

    /**
     * Get app.url as the request's root reads: its scheme and host, its port when it isn't the scheme's default, and its path, with no trailing slash.
     */
    protected function base(): string
    {
        $url = parse_url((string) config('app.url'));
        $scheme = strtolower($url['scheme'] ?? 'http');
        $host = strtolower($url['host'] ?? 'localhost');
        $port = $url['port'] ?? null;
        $path = rtrim($url['path'] ?? '', '/');

        if ($port === null || $port === ($scheme === 'https' ? 443 : 80)) {
            return "{$scheme}://{$host}{$path}";
        }

        return "{$scheme}://{$host}:{$port}{$path}";
    }
}
