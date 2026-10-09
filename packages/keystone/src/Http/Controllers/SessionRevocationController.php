<?php

namespace ClaudioDekker\Keystone\Http\Controllers;

use ClaudioDekker\Keystone\AccountChange;
use ClaudioDekker\Keystone\AccountChanges;
use ClaudioDekker\Keystone\Exceptions\CurrentSession;
use ClaudioDekker\Keystone\Http\PageValues\SessionRow;
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
abstract class SessionRevocationController extends Controller
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
     * Show one of the account's other sessions, for the user to confirm signing it out.
     */
    public function show(Request $request, string $session): Response|Responsable
    {
        (new SudoGate(Keystone::guard()))->enforce($request);

        $guard = Keystone::guard();
        /** @var Model&KeystoneUser $account */
        $account = $guard->user();
        $sessions = $guard->sessions($account);

        if ($sessions === null) {
            return $this->refuseUnlisted($request);
        }

        $found = $sessions->find($session);

        if ($found === null) {
            return $this->refuseUnknownSession($request);
        }

        if ($found->current) {
            return $this->sendRevocationRefused($request, $session, __('keystone::messages.current_session'));
        }

        return $this->sendRevocationPage($request, SessionRow::listOf([$found])[0]);
    }

    /**
     * Sign out one of the account's other sessions, forgetting the remember token it stored and leaving every other session alone.
     */
    public function destroy(Request $request, string $session): Response|Responsable
    {
        (new SudoGate(Keystone::guard()))->enforce($request);

        $guard = Keystone::guard();
        /** @var Model&KeystoneUser $account */
        $account = $guard->user();

        if ($guard->sessions($account) === null) {
            return $this->refuseUnlisted($request);
        }

        try {
            $revoked = (new AccountChanges($guard))->change($account, fn (AccountChange $change) => $change->revokeSession($session));
        } catch (CurrentSession) {
            return $this->sendRevocationRefused($request, $session, __('keystone::messages.current_session'));
        }

        if (! $revoked) {
            return $this->refuseUnknownSession($request);
        }

        Status::SESSION_REVOKED->flash($request);

        return $this->sendSessionRevoked($request);
    }

    /**
     * Respond with the page confirming the session's sign-out.
     */
    abstract protected function sendRevocationPage(Request $request, SessionRow $session): Response|Responsable;

    /**
     * Respond to a refused sign-out, with the message saying why the session is kept.
     */
    abstract protected function sendRevocationRefused(Request $request, string $session, string $message): Response|Responsable;

    /**
     * Respond to a signed-out session.
     */
    abstract protected function sendSessionRevoked(Request $request): Response|Responsable;

    /**
     * Respond to a handle no live session of the account has.
     */
    abstract protected function sendSessionNotFound(Request $request): Response|Responsable;

    /**
     * Respond to a session driver that can't list the account's sessions.
     */
    abstract protected function sendSessionsUnavailable(Request $request): Response|Responsable;

    /**
     * Send the user back from a handle no live session of the account has, saying so.
     */
    protected function refuseUnknownSession(Request $request): Response|Responsable
    {
        Status::SESSION_NOT_FOUND->flash($request);

        return $this->sendSessionNotFound($request);
    }

    /**
     * Send the user back from a session driver that can't list the account's sessions, saying so.
     */
    protected function refuseUnlisted(Request $request): Response|Responsable
    {
        Status::SESSIONS_UNAVAILABLE->flash($request);

        return $this->sendSessionsUnavailable($request);
    }
}
