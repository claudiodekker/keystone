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
     * The request attribute set when the gate refused the request, so its response gets the hardening floor on any route.
     */
    public const string REFUSED = 'keystone.sudo_refused';

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
     * Let the request through when its session holds a live sudo grant bound to the subnet it comes from, else refuse it.
     *
     * Runs any number of times in a request with the same answer: a pass changes nothing, and a refusal throws before the next call.
     * It records nothing but a live grant used from another subnet.
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

        if ($grant?->subnet->equals($this->guard->subnet())) {
            return;
        }

        if ($grant !== null) {
            $this->guard->endSudo();

            $this->recorder->record(SecurityEventType::SUDO_NETWORK_CHANGED, account: $account);
        }

        $this->guard->beginSudo(IntendedUrl::afterSudo($request, (string) config('app.url')));

        $request->attributes->set(self::REFUSED, true);

        throw new SudoRequired;
    }
}
