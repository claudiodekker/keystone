<?php

namespace ClaudioDekker\Keystone\Http\Controllers;

use ClaudioDekker\Keystone\Demand;
use ClaudioDekker\Keystone\Http\Concerns\RefusesSignedInUsers;
use ClaudioDekker\Keystone\Http\Concerns\ResolvesEnrollmentSignIn;
use ClaudioDekker\Keystone\Http\PageValues\RecoveryCodesPage;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\RecoveryCodeType;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\PendingSignIn;
use ClaudioDekker\Keystone\RecoveryCodeSetup;
use ClaudioDekker\Keystone\SignInDecision;
use ClaudioDekker\Keystone\StepKind;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
abstract class RecoveryCodesController extends Controller
{
    use RefusesSignedInUsers;
    use ResolvesEnrollmentSignIn;

    /**
     * Get the middleware that runs before the controller's actions.
     */
    public static function middleware(): array
    {
        return [
            static::throttle(StepKind::VIEW, 'show'),
            static::throttle(StepKind::SUBMIT, 'store'),
        ];
    }

    /**
     * Show the set of recovery codes staged for the held account, the same set on every visit until it is saved.
     */
    public function show(Request $request): Response|Responsable
    {
        if (Keystone::guard()->check()) {
            return $this->refuseSignedIn();
        }

        $pending = $this->pending();

        if ($pending === null) {
            return $this->refuseWithoutEnrollment();
        }

        $elsewhere = $this->elsewhere($request, $pending);

        if ($elsewhere !== null) {
            return $elsewhere;
        }

        $setup = new RecoveryCodeSetup(Keystone::guard());

        return $this->sendRecoveryCodesPage($request, new RecoveryCodesPage($setup->staged()));
    }

    /**
     * Save the staged set once the user types one of its codes back, completing the sign-in.
     */
    public function store(Request $request): Response|Responsable
    {
        if (Keystone::guard()->check()) {
            return $this->refuseSignedIn();
        }

        $pending = $this->pending();

        if ($pending === null) {
            return $this->refuseWithoutEnrollment();
        }

        $elsewhere = $this->elsewhere($request, $pending);

        if ($elsewhere !== null) {
            return $elsewhere;
        }

        $validator = Validator::make($request->all(), (new RecoveryCodeType)->rules(Surface::ENROLLMENT));

        if ($validator->fails()) {
            return redirect()->route('login.recovery-codes')->withErrors($validator->errors());
        }

        $setup = new RecoveryCodeSetup(Keystone::guard());
        $demand = $setup->confirm($pending, (string) $validator->validated()[RecoveryCodeType::FIELD]);

        return match ($demand) {
            Demand::REFUSE => $this->sendRecoveryCodeRefused($request, __('keystone::messages.recovery_code_mismatch')),
            Demand::CHALLENGE => $this->refuseWithoutEnrollment(),
            Demand::ENROLLMENT => $this->sendSecondFactorOwed($request),
            Demand::SIGN_IN => $this->sendRecoveryCodesSaved($request, $pending->intendedUrl),
        };
    }

    /**
     * Respond with the page showing the staged recovery codes and asking for one back.
     */
    abstract protected function sendRecoveryCodesPage(Request $request, RecoveryCodesPage $page): Response|Responsable;

    /**
     * Respond to a code typed back that isn't one of the staged set, with the message for its field.
     */
    abstract protected function sendRecoveryCodeRefused(Request $request, string $message): Response|Responsable;

    /**
     * Respond to a held account that owes a second factor before its recovery codes, sending the user on to enroll it.
     */
    abstract protected function sendSecondFactorOwed(Request $request): Response|Responsable;

    /**
     * Respond to saved recovery codes, sending the signed-in user on to the intended URL.
     */
    abstract protected function sendRecoveryCodesSaved(Request $request, string $intendedUrl): Response|Responsable;

    /**
     * Send the held account on when it owes something other than recovery codes, or nothing at all.
     */
    protected function elsewhere(Request $request, PendingSignIn $pending): Response|Responsable|null
    {
        $decision = new SignInDecision;

        if ($decision->next($pending) !== Demand::ENROLLMENT) {
            Keystone::guard()->forgetPending();

            return $this->refuseWithoutEnrollment();
        }

        return $decision->pendingOwesSecondFactor($pending) ? $this->sendSecondFactorOwed($request) : null;
    }
}
