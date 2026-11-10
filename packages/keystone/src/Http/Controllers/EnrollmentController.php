<?php

namespace ClaudioDekker\Keystone\Http\Controllers;

use ClaudioDekker\Keystone\Http\Middleware\FlashOnlyTheIdentifier;
use ClaudioDekker\Keystone\Http\Middleware\LimitRequests;
use ClaudioDekker\Keystone\Http\Pages\EnrollmentPage;
use ClaudioDekker\Keystone\Http\Pages\RecoveryCodesPage;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\RateLimits\StepKind;
use ClaudioDekker\Keystone\SignIn\Enrollment;
use ClaudioDekker\Keystone\SignIn\EnrollmentExpired;
use ClaudioDekker\Keystone\SignIn\Held;
use ClaudioDekker\Keystone\SignIn\NoPendingSignIn;
use ClaudioDekker\Keystone\SignIn\RecoveryCodeIncorrect;
use ClaudioDekker\Keystone\SignIn\Refused;
use ClaudioDekker\Keystone\Status;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\Middleware;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @api
 */
abstract class EnrollmentController implements KeystoneController
{
    /**
     * Get the middleware every enrollment action runs, whatever the app's subclass overrides.
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
     * Show the step the session's pending sign-in owes next: its second factor, then its recovery codes.
     */
    public function show(Request $request, Enrollment $enrollment): Response|Responsable
    {
        $outcome = $enrollment->open();

        if ($outcome instanceof NoPendingSignIn) {
            return $this->sendNoPendingSignIn($request);
        }

        if ($outcome instanceof RecoveryCodesPage) {
            return $this->sendRecoveryCodesPage($request, $outcome);
        }

        return $this->sendEnrollmentPage($request, $outcome);
    }

    /**
     * Take an answer of the credential type the route names to the step the pending sign-in owes.
     */
    public function store(Request $request, string $type, Enrollment $enrollment): Response|Responsable
    {
        $credentialType = $enrollment->type($type) ?? throw new NotFoundHttpException;

        $outcome = $enrollment->answer($credentialType, $request->all());

        if ($outcome instanceof NoPendingSignIn) {
            return $this->sendNoPendingSignIn($request);
        }

        if ($outcome instanceof Refused) {
            return $this->sendEnrollmentRefused($request, $this->field($credentialType), Status::CREDENTIAL_INVALID->label());
        }

        if ($outcome instanceof RecoveryCodeIncorrect) {
            return $this->sendEnrollmentRefused($request, $this->field($credentialType), Status::RECOVERY_CODE_INCORRECT->label());
        }

        if ($outcome instanceof EnrollmentExpired) {
            return $this->sendEnrollmentExpired($request, $this->field($credentialType), Status::ENROLLMENT_EXPIRED->label());
        }

        if ($outcome instanceof Held) {
            return $this->sendSecondFactorEnrolled($request);
        }

        return $this->sendSignedIn($request, $outcome->redirectTo);
    }

    /**
     * Cancel the session's pending sign-in, so the user signs in again from the start.
     */
    public function destroy(Request $request, Enrollment $enrollment): Response|Responsable
    {
        $enrollment->cancel();

        return $this->sendEnrollmentCancelled($request);
    }

    /**
     * Get the field a refusal of the type's answer is shown on: the first the type validates, else its name.
     */
    protected function field(CredentialType $type): string
    {
        return array_key_first($type->rules(Surface::ENROLLMENT)) ?? $type->name();
    }

    /**
     * Render the second-factor step from its page value.
     */
    abstract protected function sendEnrollmentPage(Request $request, EnrollmentPage $page): Response|Responsable;

    /**
     * Render the recovery-code step from its page value, which shows the staged codes once more.
     */
    abstract protected function sendRecoveryCodesPage(Request $request, RecoveryCodesPage $page): Response|Responsable;

    /**
     * Send a session that holds no pending sign-in at enrollment to sign in.
     */
    abstract protected function sendNoPendingSignIn(Request $request): Response|Responsable;

    /**
     * Refuse the answer with the translated message on the type's field, keeping what the step showed.
     */
    abstract protected function sendEnrollmentRefused(Request $request, string $field, string $message): Response|Responsable;

    /**
     * Refuse an answer to a step whose ceremony ended with the translated message on the type's field, once a new one started.
     */
    abstract protected function sendEnrollmentExpired(Request $request, string $field, string $message): Response|Responsable;

    /**
     * Send the user who enrolled their second factor on to save their recovery codes.
     */
    abstract protected function sendSecondFactorEnrolled(Request $request): Response|Responsable;

    /**
     * Send the user on after enrollment completed the sign-in.
     */
    abstract protected function sendSignedIn(Request $request, string $redirectTo): Response|Responsable;

    /**
     * Send the user to sign in again after cancelling the pending sign-in.
     */
    abstract protected function sendEnrollmentCancelled(Request $request): Response|Responsable;
}
