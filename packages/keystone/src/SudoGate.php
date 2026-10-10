<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Exceptions\SudoRequired;
use Illuminate\Auth\AuthenticationException;
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
        if ($this->guard->user() === null) {
            $request->attributes->set(self::REFUSED, true);

            throw new AuthenticationException;
        }

        if ($this->liveGrant() !== null) {
            return;
        }

        $this->guard->endSudoOnNetworkChange();

        $this->guard->beginSudo($this->intendedUrl($request));

        $request->attributes->set(self::REFUSED, true);

        throw new SudoRequired;
    }

    /**
     * Get the session's live sudo grant when it is bound to the subnet the request comes from, so the gate lets the request through.
     */
    public function liveGrant(): ?SudoGrant
    {
        $grant = $this->guard->sudoGrant();

        return $grant?->subnet->equals($this->guard->context()->subnet()) ? $grant : null;
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
