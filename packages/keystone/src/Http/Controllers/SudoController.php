<?php

namespace ClaudioDekker\Keystone\Http\Controllers;

use ClaudioDekker\Keystone\Http\Middleware\FlashOnlyTheIdentifier;
use ClaudioDekker\Keystone\Http\Middleware\LimitRequests;
use ClaudioDekker\Keystone\Http\Pages\SudoPage;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\RateLimits\StepKind;
use ClaudioDekker\Keystone\SignIn\LastRecoveryCodeKept;
use ClaudioDekker\Keystone\SignIn\NoSudoInProgress;
use ClaudioDekker\Keystone\SignIn\Refused;
use ClaudioDekker\Keystone\SignIn\Sudo;
use ClaudioDekker\Keystone\SignIn\SudoFirstFactorPassed;
use ClaudioDekker\Keystone\Status;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\Middleware;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @api
 */
abstract class SudoController implements KeystoneController
{
    /**
     * Get the middleware every sudo action runs, whatever the app's subclass overrides.
     *
     * Every action but store() and destroy() counts as a view, so an action the app adds is limited too.
     *
     * @return list<Middleware|string>
     */
    public static function middleware(): array
    {
        return [
            FlashOnlyTheIdentifier::class,
            new Middleware(LimitRequests::for(StepKind::CHANGE), only: ['destroy']),
            new Middleware(LimitRequests::for(StepKind::SUBMIT), only: ['store']),
            new Middleware(LimitRequests::for(StepKind::VIEW), except: ['destroy', 'store']),
        ];
    }

    /**
     * Show the step of the session's sudo-in-progress.
     */
    public function show(Request $request, Sudo $sudo): Response|Responsable
    {
        $outcome = $sudo->open();

        if ($outcome instanceof NoSudoInProgress) {
            return $this->sendNoSudoInProgress($request, $outcome->redirectTo);
        }

        return $this->sendSudoPage($request, $outcome);
    }

    /**
     * Take an answer to the sudo-in-progress's step with the credential type the route names.
     */
    public function store(Request $request, string $type, Sudo $sudo): Response|Responsable
    {
        $credentialType = $sudo->type($type) ?? throw new NotFoundHttpException;

        $outcome = $sudo->answer($credentialType, $request->all());

        if ($outcome instanceof NoSudoInProgress) {
            return $this->sendNoSudoInProgress($request, $outcome->redirectTo);
        }

        if ($outcome instanceof Refused) {
            return $this->sendSudoRefused($request, $this->field($credentialType, $sudo), Status::CREDENTIAL_INVALID->label());
        }

        if ($outcome instanceof LastRecoveryCodeKept) {
            return $this->sendSudoLastRecoveryCodeKept($request, $this->field($credentialType, $sudo), Status::LAST_RECOVERY_CODE->label());
        }

        if ($outcome instanceof SudoFirstFactorPassed) {
            return $this->sendSudoSecondStep($request);
        }

        return $this->sendSudoGranted($request, $outcome->redirectTo);
    }

    /**
     * Get the field a refusal of the type's answer is shown on: the first the type validates at the step, else its name.
     */
    protected function field(CredentialType $type, Sudo $sudo): string
    {
        return array_key_first($type->rules($sudo->surface())) ?? $type->name();
    }

    /**
     * Render the sudo page from its page value.
     */
    abstract protected function sendSudoPage(Request $request, SudoPage $page): Response|Responsable;

    /**
     * Send a session that holds no sudo-in-progress on to where it was going.
     */
    abstract protected function sendNoSudoInProgress(Request $request, string $redirectTo): Response|Responsable;

    /**
     * Refuse the answer with the translated message on the type's field, keeping the sudo-in-progress.
     */
    abstract protected function sendSudoRefused(Request $request, string $field, string $message): Response|Responsable;

    /**
     * Refuse the account's last recovery code with the translated message on its field, keeping the code and the sudo-in-progress.
     */
    abstract protected function sendSudoLastRecoveryCodeKept(Request $request, string $field, string $message): Response|Responsable;

    /**
     * Send the user whose first factor passed on to the second step, which asks for a second factor.
     */
    abstract protected function sendSudoSecondStep(Request $request): Response|Responsable;

    /**
     * Send the user on to where they were going once sudo is granted.
     */
    abstract protected function sendSudoGranted(Request $request, string $redirectTo): Response|Responsable;
}
