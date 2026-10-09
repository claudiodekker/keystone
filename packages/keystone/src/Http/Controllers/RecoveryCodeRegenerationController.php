<?php

namespace ClaudioDekker\Keystone\Http\Controllers;

use ClaudioDekker\Keystone\Http\PageValues\RecoveryCodeRegenerationPage;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\KeystoneUser;
use ClaudioDekker\Keystone\Methods\RecoveryCodeType;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\RateLimiter;
use ClaudioDekker\Keystone\RecoveryCodeRegeneration;
use ClaudioDekker\Keystone\RecoveryCodeRegenerationAttempt;
use ClaudioDekker\Keystone\RecoveryCodeRegenerationResult;
use ClaudioDekker\Keystone\RecoveryCodes;
use ClaudioDekker\Keystone\RequestContext;
use ClaudioDekker\Keystone\Status;
use ClaudioDekker\Keystone\StepKind;
use ClaudioDekker\Keystone\SudoGate;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
abstract class RecoveryCodeRegenerationController extends Controller
{
    /**
     * Get the middleware that runs before the controller's actions.
     */
    public static function middleware(): array
    {
        return [
            static::throttle(StepKind::START, 'create'),
            static::throttle(StepKind::SUBMIT, 'store'),
            static::throttle(StepKind::CHANGE, 'destroy'),
            static::sudo('create', 'store'),
        ];
    }

    /**
     * Stage a new set of recovery codes for the signed-in account and show it, the same set on every visit until it is saved or discarded.
     */
    public function create(Request $request): Response|Responsable
    {
        (new SudoGate(Keystone::guard()))->enforce($request);

        /** @var Model&KeystoneUser $account */
        $account = Keystone::guard()->user();
        $staged = (new RecoveryCodeRegeneration(Keystone::guard()))->resolve($account);

        return $this->sendRecoveryCodeRegenerationPage($request, new RecoveryCodeRegenerationPage(
            codes: $staged->codes,
            replaces: (new RecoveryCodes($account))->hasRemaining($account->getKey()),
            status: Status::flashed($request)?->label(),
        ));
    }

    /**
     * Store the staged set once the user types one of its codes back, replacing the account's recovery codes.
     */
    public function store(Request $request): Response|Responsable
    {
        (new SudoGate(Keystone::guard()))->enforce($request);

        $staged = (new RecoveryCodeRegeneration(Keystone::guard()))->staged();

        if ($staged === null) {
            return $this->refuseExpiredStaging($request);
        }

        $validator = Validator::make($request->all(), (new RecoveryCodeType)->rules(Surface::ENROLLMENT));

        if ($validator->fails()) {
            return redirect()->route('security.recovery-codes.regenerate')->withErrors($validator->errors());
        }

        $result = (new RecoveryCodeRegenerationAttempt(Keystone::guard(), new RateLimiter($request, app(RequestContext::class), Keystone::guard())))
            ->attempt($staged, (string) $validator->validated()[RecoveryCodeType::FIELD]);

        return match ($result) {
            RecoveryCodeRegenerationResult::REGENERATED => $this->regenerated($request),
            RecoveryCodeRegenerationResult::REFUSED => $this->sendRecoveryCodeRegenerationRefused($request, __('keystone::messages.recovery_code_mismatch')),
            RecoveryCodeRegenerationResult::EXPIRED => $this->refuseExpiredStaging($request),
            RecoveryCodeRegenerationResult::SUDO_ENDED => $this->refuseWithoutSudo($request),
        };
    }

    /**
     * Discard the staged set, leaving the account's recovery codes as they were.
     */
    public function destroy(Request $request): Response|Responsable
    {
        if (! Keystone::guard()->check()) {
            return redirect()->route('login');
        }

        (new RecoveryCodeRegeneration(Keystone::guard()))->close();

        return $this->sendRecoveryCodeRegenerationCancelled($request);
    }

    /**
     * Respond with the page showing the staged set and asking for one of its codes back.
     */
    abstract protected function sendRecoveryCodeRegenerationPage(Request $request, RecoveryCodeRegenerationPage $page): Response|Responsable;

    /**
     * Respond to a typed code that isn't one of the staged set, with the message for its field.
     */
    abstract protected function sendRecoveryCodeRegenerationRefused(Request $request, string $message): Response|Responsable;

    /**
     * Respond to a code typed back after its staged set ended, sending the user back to the regeneration step for a fresh set.
     */
    abstract protected function sendRecoveryCodeRegenerationExpired(Request $request): Response|Responsable;

    /**
     * Respond to the staged set saved as the account's recovery codes.
     */
    abstract protected function sendRecoveryCodesRegenerated(Request $request): Response|Responsable;

    /**
     * Respond to a discarded regeneration.
     */
    abstract protected function sendRecoveryCodeRegenerationCancelled(Request $request): Response|Responsable;

    /**
     * Send the user on from a saved set, saying so with the status.
     */
    protected function regenerated(Request $request): Response|Responsable
    {
        Status::RECOVERY_CODES_REGENERATED->flash($request);

        return $this->sendRecoveryCodesRegenerated($request);
    }

    /**
     * Send the user back from a code whose staged set is gone, saying a fresh set replaced it.
     */
    protected function refuseExpiredStaging(Request $request): Response|Responsable
    {
        Status::RECOVERY_CODES_EXPIRED->flash($request);

        return $this->sendRecoveryCodeRegenerationExpired($request);
    }

    /**
     * Refuse a code whose session lost its sudo or its sign-in while it was checked, as the gate does.
     */
    protected function refuseWithoutSudo(Request $request): Response|Responsable
    {
        (new SudoGate(Keystone::guard()))->enforce($request);

        return $this->refuseExpiredStaging($request);
    }
}
