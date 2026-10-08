<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Exceptions\SudoRequired;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * @internal
 */
class SudoGate
{
    /**
     * The request attribute set when the gate refused the request.
     */
    protected const string REFUSED = 'keystone.sudo_refused';

    /**
     * Create a new sudo gate instance.
     */
    public function __construct(
        protected KeystoneGuard $guard,
        protected SecurityEventRecorder $recorder = new SecurityEventRecorder,
    ) {
        //
    }

    /**
     * Determine if the gate refused the request, so its response gets the hardening floor on any route.
     */
    public static function refused(Request $request): bool
    {
        return $request->attributes->getBoolean(self::REFUSED);
    }

    /**
     * Let the request through when its session holds a live sudo grant bound to the subnet it comes from, else refuse it.
     *
     * @throws AuthenticationException for a guest
     * @throws SudoRequired
     */
    public function enforce(Request $request): void
    {
        /** @var (Model&KeystoneUser)|null $account */
        $account = $this->guard->user();

        if ($account === null) {
            $request->attributes->set(self::REFUSED, true);

            throw new AuthenticationException;
        }

        $grant = $this->guard->sudoGrant();

        if ($grant?->subnet->equals(RequestContext::of($request)->subnet())) {
            return;
        }

        if ($grant !== null) {
            $this->guard->endSudo();

            $this->recorder->record(SecurityEventType::SUDO_NETWORK_CHANGED, account: $account);
        }

        $this->guard->beginSudo($this->intendedUrl($request));

        $request->attributes->set(self::REFUSED, true);

        throw new SudoRequired;
    }

    /**
     * Get where the refused request goes once sudo is granted: the page a browser asked for, else the page a live sudo-in-progress already holds, else the page the request came from.
     */
    protected function intendedUrl(Request $request): string
    {
        $appUrl = (string) config('app.url');

        return IntendedUrl::requested($request, $appUrl)
            ?? $this->guard->sudoInProgress()->intendedUrl
            ?? IntendedUrl::previous($request, $appUrl);
    }
}
