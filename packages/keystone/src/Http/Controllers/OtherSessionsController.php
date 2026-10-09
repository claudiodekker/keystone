<?php

namespace ClaudioDekker\Keystone\Http\Controllers;

use ClaudioDekker\Keystone\AccountChange;
use ClaudioDekker\Keystone\AccountChanges;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\KeystoneUser;
use ClaudioDekker\Keystone\Status;
use ClaudioDekker\Keystone\StepKind;
use ClaudioDekker\Keystone\SudoGate;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
abstract class OtherSessionsController extends Controller
{
    /**
     * Get the middleware that runs before the controller's actions.
     */
    public static function middleware(): array
    {
        return [
            static::throttle(StepKind::VIEW, 'show'),
            static::throttle(StepKind::CHANGE, 'destroy'),
            static::sudo('show', 'destroy'),
        ];
    }

    /**
     * Show the step on which the user confirms signing out every other session of the account.
     */
    public function show(Request $request): Response|Responsable
    {
        (new SudoGate(Keystone::guard()))->enforce($request);

        return $this->sendSignOutOthersPage($request);
    }

    /**
     * Sign out every session of the signed-in account but this one, which stays signed in on a new session id.
     */
    public function destroy(Request $request): Response|Responsable
    {
        (new SudoGate(Keystone::guard()))->enforce($request);

        $guard = Keystone::guard();
        /** @var Model&KeystoneUser $account */
        $account = $guard->user();

        (new AccountChanges($guard))->change($account, fn (AccountChange $change) => $change->signOutOthers());

        Status::OTHER_SESSIONS_REVOKED->flash($request);

        return $this->sendOtherSessionsRevoked($request);
    }

    /**
     * Respond with the page confirming the sign-out of every other session.
     */
    abstract protected function sendSignOutOthersPage(Request $request): Response|Responsable;

    /**
     * Respond to the account's other sessions being signed out.
     */
    abstract protected function sendOtherSessionsRevoked(Request $request): Response|Responsable;
}
