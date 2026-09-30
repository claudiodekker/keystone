<?php

namespace ClaudioDekker\Keystone\Http\Middleware;

use ClaudioDekker\Keystone\Http\Middleware\Concerns\RecognizesKeystoneRoutes;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\KeystoneUser;
use ClaudioDekker\Keystone\SecurityEventRecorder;
use ClaudioDekker\Keystone\SecurityEventType;
use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 */
class RefuseCrossSiteRequests
{
    use RecognizesKeystoneRoutes;

    /**
     * The methods that only read, which need no proof of where they came from.
     */
    protected const array READING_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * Create a new middleware instance.
     */
    public function __construct(
        protected Encrypter $encrypter,
    ) {
        //
    }

    /**
     * Refuse a Keystone mutation that can't show it came from the app or an origin it trusts, whatever the app's CSRF exceptions say.
     *
     * @param  Closure(Request): Response  $next
     *
     * @throws TokenMismatchException
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), self::READING_METHODS, true) || ! $this->routesToKeystone($request)) {
            return $next($request);
        }

        if ($this->isSameOrigin($request) || $this->tokenMatches($request) || $this->originMatches($request)) {
            return $next($request);
        }

        $this->recordRejection();

        throw new TokenMismatchException('Cross-site request refused.');
    }

    /**
     * Determine if the browser says the request came from the app's own origin.
     */
    protected function isSameOrigin(Request $request): bool
    {
        return $request->header('Sec-Fetch-Site') === 'same-origin';
    }

    /**
     * Determine if the request carries the session's CSRF token.
     */
    protected function tokenMatches(Request $request): bool
    {
        $expected = $request->session()->token();
        $token = $this->token($request);

        return $token !== '' && hash_equals($expected, $token);
    }

    /**
     * Get the CSRF token the request carries, from its input, its header or the encrypted XSRF-TOKEN header.
     */
    protected function token(Request $request): string
    {
        $token = $request->input('_token') ?: $request->header('X-CSRF-TOKEN');

        if (is_string($token) && $token !== '') {
            return $token;
        }

        $encrypted = $request->header('X-XSRF-TOKEN');

        if (! is_string($encrypted) || $encrypted === '') {
            return '';
        }

        try {
            $decrypted = $this->encrypter->decrypt($encrypted, PreventRequestForgery::serialized());
        } catch (DecryptException) {
            return '';
        }

        return is_string($decrypted) ? CookieValuePrefix::remove($decrypted) : '';
    }

    /**
     * Determine if the request's Origin header names the app's own origin or one keystone.trusted_origins lists.
     */
    protected function originMatches(Request $request): bool
    {
        $origin = $request->header('Origin');
        $origins = [$request->getSchemeAndHttpHost(), ...$this->trustedOrigins()];

        return is_string($origin) && in_array(strtolower($origin), $origins, true);
    }

    /**
     * Get the other origins keystone.trusted_origins lets make Keystone mutations, in the form an Origin header takes.
     *
     * @return list<string>
     */
    protected function trustedOrigins(): array
    {
        $origins = config('keystone.trusted_origins', []);

        if (! is_array($origins)) {
            return [];
        }

        $strings = array_filter($origins, is_string(...));

        return array_values(array_map(fn (string $origin) => strtolower(rtrim($origin, '/')), $strings));
    }

    /**
     * Record the rejection, on the signed-in account's trail when there is one.
     */
    protected function recordRejection(): void
    {
        /** @var (Model&KeystoneUser)|null $account */
        $account = Keystone::guard()->user();

        (new SecurityEventRecorder)->record(
            SecurityEventType::REQUEST_REJECTED,
            account: $account,
            reason: 'keystone.cross_site',
        );
    }
}
