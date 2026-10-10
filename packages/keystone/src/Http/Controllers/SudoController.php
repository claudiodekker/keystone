<?php

namespace ClaudioDekker\Keystone\Http\Controllers;

use ClaudioDekker\Keystone\CredentialAttempt;
use ClaudioDekker\Keystone\Exceptions\LastRecoveryCode;
use ClaudioDekker\Keystone\Http\PageValues\SudoPage;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\KeystoneUser;
use ClaudioDekker\Keystone\RateLimiter;
use ClaudioDekker\Keystone\RequestContext;
use ClaudioDekker\Keystone\SecurityEventRecorder;
use ClaudioDekker\Keystone\SecurityEventType;
use ClaudioDekker\Keystone\SignInDecision;
use ClaudioDekker\Keystone\Status;
use ClaudioDekker\Keystone\StepKind;
use ClaudioDekker\Keystone\SudoAttempt;
use ClaudioDekker\Keystone\SudoResult;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Timebox;
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
        return [
            static::throttle(StepKind::VIEW, 'show'),
            static::throttle(StepKind::SUBMIT, 'store'),
            static::throttle(StepKind::CHANGE, 'destroy'),
        ];
    }

    /**
     * Show the step the sudo-in-progress is at, offering what a sign-in of this account would ask for there, inside the timing floor.
     */
    public function show(Request $request): Response|Responsable
    {
        return (new Timebox)->call(fn () => $this->offer($request), CredentialAttempt::TIMING_FLOOR_MICROSECONDS);
    }

    /**
     * Answer the step with a proof of the credential type or a recovery code, earning sudo once nothing more is owed.
     */
    public function store(Request $request, string $type): Response|Responsable
    {
        $guard = Keystone::guard();

        if (! $guard->check()) {
            return $this->refuseGuest();
        }

        /** @var Model&KeystoneUser $account */
        $account = $guard->user();
        $progress = $guard->sudoInProgress();

        if ($progress === null) {
            return $this->refuseWithoutSudoInProgress();
        }

        $credentialType = (new SignInDecision)->replayOffered($account, $progress->firstFactor, $type);

        if ($credentialType === null) {
            return $this->sendSudoRefused($request, $type, __('keystone::messages.invalid_credential'));
        }

        $validator = Validator::make($request->all(), $credentialType->rules($progress->surface()));

        if ($validator->fails()) {
            return redirect()->route('sudo')->withErrors($validator->errors());
        }

        $attempt = new SudoAttempt($guard, new RateLimiter($request, app(RequestContext::class), $guard));

        try {
            $result = $attempt->attempt($progress, $credentialType, $validator->validated());
        } catch (LastRecoveryCode) {
            return $this->sendSudoRefused($request, $credentialType->name(), __('keystone::messages.last_recovery_code'));
        }

        return match ($result) {
            SudoResult::REFUSED => $this->sendSudoRefused($request, $credentialType->name(), __('keystone::messages.invalid_credential')),
            SudoResult::CHALLENGE_OWED => $this->sendSudoChallengeOwed($request),
            SudoResult::GRANTED => $this->sendSudoGranted($request, $progress->intendedUrl),
        };
    }

    /**
     * End sudo, dropping the session's grant and any sudo-in-progress.
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
     * Respond with the sudo page.
     */
    abstract protected function sendSudoPage(Request $request, SudoPage $page): Response|Responsable;

    /**
     * Respond to a refused answer, with the message for the credential type's field.
     */
    abstract protected function sendSudoRefused(Request $request, string $type, string $message): Response|Responsable;

    /**
     * Respond to a passed first factor whose account still owes the challenge, sending the user on to it.
     */
    abstract protected function sendSudoChallengeOwed(Request $request): Response|Responsable;

    /**
     * Respond to a granted sudo, sending the user on to the intended URL.
     */
    abstract protected function sendSudoGranted(Request $request, string $intendedUrl): Response|Responsable;

    /**
     * Respond to an ended sudo.
     */
    abstract protected function sendSudoEnded(Request $request): Response|Responsable;

    /**
     * Offer what the step the sudo-in-progress is at asks for, or send the user on when the second factor it owes went away.
     */
    protected function offer(Request $request): Response|Responsable
    {
        $guard = Keystone::guard();

        if (! $guard->check()) {
            return $this->refuseGuest();
        }

        /** @var Model&KeystoneUser $account */
        $account = $guard->user();
        $progress = $guard->sudoInProgress();

        if ($progress === null) {
            return $this->refuseWithoutSudoInProgress();
        }

        $decision = new SignInDecision;

        if ($progress->firstFactor !== null && ! $decision->holdsSecondFactor($account, $progress->firstFactor)) {
            $guard->endSudo();

            return redirect($progress->intendedUrl);
        }

        $types = $this->typeOptions($decision->replayOffer($account, $progress->firstFactor), $progress->surface());

        $page = new SudoPage(
            types: $types,
            preselect: $types[0]['type'] ?? null,
            surface: $progress->surface()->value,
        );

        return $this->sendSudoPage($request, $page);
    }

    /**
     * Send a guest away from a signed-in step.
     */
    protected function refuseGuest(): RedirectResponse
    {
        return redirect()->route('login');
    }

    /**
     * Send a session nothing was demanded of away from the sudo steps.
     */
    protected function refuseWithoutSudoInProgress(): RedirectResponse
    {
        return redirect('/');
    }
}
