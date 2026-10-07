<?php

namespace ClaudioDekker\Keystone\Http\Controllers;

use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\KeystoneUser;
use ClaudioDekker\Keystone\SecurityEventRecorder;
use ClaudioDekker\Keystone\SecurityEventType;
use ClaudioDekker\Keystone\Status;
use ClaudioDekker\Keystone\StepKind;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
abstract class SudoController extends Controller
{
    /**
     * Get the middleware that runs before the controller's actions.
     */
    public static function middleware(): array
    {
        return [static::throttle(StepKind::CHANGE, 'destroy')];
    }

    /**
     * End sudo, dropping the session's grant.
     */
    public function destroy(Request $request): Response|Responsable
    {
        $guard = Keystone::guard();

        if (! $guard->check()) {
            return $this->refuseGuest();
        }

        /** @var Model&KeystoneUser $account */
        $account = $guard->user();

        if (! is_null($guard->sudoGrant())) {
            (new SecurityEventRecorder)->record(
                SecurityEventType::SUDO_REVOKED,
                account: $account,
            );
        }

        $guard->endSudo();

        Status::SUDO_REVOKED->flash($request);

        return $this->sendSudoEnded($request);
    }

    /**
     * Respond to an ended sudo.
     */
    abstract protected function sendSudoEnded(Request $request): Response|Responsable;

    /**
     * Send a guest away from a signed-in step.
     */
    protected function refuseGuest(): RedirectResponse
    {
        return redirect()->route('login');
    }
}
