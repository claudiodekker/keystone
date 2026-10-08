<?php

namespace ClaudioDekker\Keystone\Http\Controllers;

use ClaudioDekker\Keystone\Demand;
use ClaudioDekker\Keystone\EnrollmentAttempt;
use ClaudioDekker\Keystone\EnrollmentCeremonies;
use ClaudioDekker\Keystone\Http\Concerns\ResolvesEnrollmentSignIn;
use ClaudioDekker\Keystone\Http\PageValues\EnrollmentFormPage;
use ClaudioDekker\Keystone\Http\PageValues\EnrollmentPage;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\PendingSignIn;
use ClaudioDekker\Keystone\RateLimiter;
use ClaudioDekker\Keystone\RequestContext;
use ClaudioDekker\Keystone\SignInDecision;
use ClaudioDekker\Keystone\Status;
use ClaudioDekker\Keystone\StepKind;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * @api
 */
abstract class EnrollmentController extends Controller
{
    use ResolvesEnrollmentSignIn;

    /**
     * Get the middleware that runs before the controller's actions.
     */
    public static function middleware(): array
    {
        return [
            static::throttle(StepKind::VIEW, 'show'),
            static::throttle(StepKind::START, 'create'),
            static::throttle(StepKind::SUBMIT, 'store'),
            static::throttle(StepKind::CHANGE, 'destroy'),
        ];
    }

    /**
     * Show the types the held account can enroll as its second factor.
     */
    public function show(Request $request): Response|Responsable
    {
        $pending = $this->owing($request);

        if (! $pending instanceof PendingSignIn) {
            return $pending;
        }

        $types = array_map(fn (CredentialType $type) => [
            'type' => $type->name(),
            'shape' => $type->surfaces()[Surface::ENROLLMENT->value]->value,
        ], $this->offer());

        $page = new EnrollmentPage(
            types: $types,
            preselect: $types[0]['type'] ?? null,
            origin: $pending->origin->value,
        );

        return $this->sendEnrollmentPage($request, $page);
    }

    /**
     * Start the type's enrollment ceremony, or show the one already running.
     */
    public function create(Request $request, string $type): Response|Responsable
    {
        $pending = $this->owing($request);

        if (! $pending instanceof PendingSignIn) {
            return $pending;
        }

        $credentialType = $this->offered($type);

        if ($credentialType === null) {
            return $this->refuseUnofferedType();
        }

        try {
            $running = (new EnrollmentCeremonies(Keystone::guard()))->start($credentialType, $pending->account);
        } catch (Throwable $e) {
            report($e);

            return $this->sendEnrollmentNotStarted($request, $credentialType->name(), __('keystone::messages.enrollment_failed'));
        }

        $page = new EnrollmentFormPage(
            type: $credentialType->name(),
            shape: $credentialType->surfaces()[Surface::ENROLLMENT->value]->value,
            ceremony: $running->page,
            status: Status::flashed($request)?->label(),
        );

        return $this->sendEnrollmentForm($request, $page);
    }

    /**
     * Answer the type's enrollment ceremony, storing the new credential and completing the sign-in when nothing else is owed.
     */
    public function store(Request $request, string $type): Response|Responsable
    {
        $pending = $this->owing($request);

        if (! $pending instanceof PendingSignIn) {
            return $pending;
        }

        $credentialType = $this->offered($type);

        if ($credentialType === null) {
            return $this->refuseUnofferedType();
        }

        $running = (new EnrollmentCeremonies(Keystone::guard()))->running($credentialType);

        if ($running === null) {
            Status::ENROLLMENT_EXPIRED->flash($request);

            return $this->sendEnrollmentExpired($request, $credentialType->name());
        }

        $validator = Validator::make($request->all(), $credentialType->rules(Surface::ENROLLMENT));

        if ($validator->fails()) {
            return redirect()->route('login.enrollment.start', ['type' => $credentialType->name()])->withErrors($validator->errors());
        }

        $attempt = new EnrollmentAttempt(Keystone::guard(), new RateLimiter($request, app(RequestContext::class), Keystone::guard()));
        $demand = $attempt->attempt($pending, $credentialType, $validator->validated(), $running->ceremony);

        return match ($demand) {
            Demand::REFUSE => $this->sendEnrollmentRefused($request, $credentialType->name(), __('keystone::messages.invalid_credential')),
            Demand::CHALLENGE => $this->refuseWithoutEnrollment(),
            Demand::ENROLLMENT => $this->sendRecoveryCodesOwed($request),
            Demand::SIGN_IN => $this->sendEnrollmentCompleted($request, $pending->intendedUrl),
        };
    }

    /**
     * Cancel the sign-in held at enrollment, leaving a guest who still owes it.
     */
    public function destroy(Request $request): Response|Responsable
    {
        $pending = $this->held();

        if (! $pending instanceof PendingSignIn) {
            return $pending;
        }

        Keystone::guard()->forgetPending();

        Status::ENROLLMENT_CANCELLED->flash($request);

        return $this->sendEnrollmentCancelled($request);
    }

    /**
     * Respond with the page listing the types the account can enroll.
     */
    abstract protected function sendEnrollmentPage(Request $request, EnrollmentPage $page): Response|Responsable;

    /**
     * Respond with the type's enrollment form, showing what its ceremony needs.
     */
    abstract protected function sendEnrollmentForm(Request $request, EnrollmentFormPage $page): Response|Responsable;

    /**
     * Respond to a type whose enrollment ceremony couldn't start, with the message for the type.
     */
    abstract protected function sendEnrollmentNotStarted(Request $request, string $type, string $message): Response|Responsable;

    /**
     * Respond to a refused enrollment answer, with the message for the type's field.
     */
    abstract protected function sendEnrollmentRefused(Request $request, string $type, string $message): Response|Responsable;

    /**
     * Respond to an answer whose ceremony expired, sending the user back to start the type's enrollment afresh.
     */
    abstract protected function sendEnrollmentExpired(Request $request, string $type): Response|Responsable;

    /**
     * Respond to a held account that still owes recovery codes, sending the user on to save them.
     */
    abstract protected function sendRecoveryCodesOwed(Request $request): Response|Responsable;

    /**
     * Respond to a completed enrollment, sending the signed-in user on to the intended URL.
     */
    abstract protected function sendEnrollmentCompleted(Request $request, string $intendedUrl): Response|Responsable;

    /**
     * Respond to a cancelled enrollment.
     */
    abstract protected function sendEnrollmentCancelled(Request $request): Response|Responsable;

    /**
     * Get the held sign-in when it still owes a second factor, or the response that sends the user where they belong instead.
     */
    protected function owing(Request $request): PendingSignIn|Response|Responsable
    {
        $pending = $this->held();

        if (! $pending instanceof PendingSignIn) {
            return $pending;
        }

        return $this->elsewhere($request, $pending) ?? $pending;
    }

    /**
     * Send the held account on when it owes something other than a second factor, or nothing at all.
     */
    protected function elsewhere(Request $request, PendingSignIn $pending): Response|Responsable|null
    {
        $decision = new SignInDecision;

        if ($decision->next($pending) !== Demand::ENROLLMENT) {
            Keystone::guard()->forgetPending();

            return $this->refuseWithoutEnrollment();
        }

        return $decision->owesSecondFactor($pending->account) ? null : $this->sendRecoveryCodesOwed($request);
    }

    /**
     * Get the types the account can enroll as its second factor.
     *
     * @return list<CredentialType>
     */
    protected function offer(): array
    {
        return (new SignInDecision)->enrollmentOffer(app(CredentialTypes::class));
    }

    /**
     * Get the offered type with the name.
     */
    protected function offered(string $name): ?CredentialType
    {
        $named = array_filter($this->offer(), fn (CredentialType $type) => $type->name() === $name);

        return array_values($named)[0] ?? null;
    }

    /**
     * Send a request for a type the account can't enroll back to the types it can.
     */
    protected function refuseUnofferedType(): RedirectResponse
    {
        return redirect()->route('login.enrollment');
    }
}
