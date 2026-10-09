<?php

namespace ClaudioDekker\Keystone\Http\Controllers;

use ClaudioDekker\Keystone\Credentials;
use ClaudioDekker\Keystone\EnrollmentCeremonies;
use ClaudioDekker\Keystone\Http\PageValues\EnrollmentFormPage;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\KeystoneUser;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\RateLimiter;
use ClaudioDekker\Keystone\RequestContext;
use ClaudioDekker\Keystone\SettingsEnrollmentAttempt;
use ClaudioDekker\Keystone\SettingsEnrollmentResult;
use ClaudioDekker\Keystone\Status;
use ClaudioDekker\Keystone\StepKind;
use ClaudioDekker\Keystone\SudoGate;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * @api
 */
abstract class CredentialEnrollmentController extends Controller
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
     * Start the type's enrollment ceremony for the signed-in account, or show the one already running.
     */
    public function create(Request $request, string $type): Response|Responsable
    {
        (new SudoGate(Keystone::guard()))->enforce($request);

        /** @var Model&KeystoneUser $account */
        $account = Keystone::guard()->user();
        $types = app(CredentialTypes::class);
        $refusal = $types->enrollmentRefusal($type);

        if ($refusal !== null) {
            return $this->sendCredentialEnrollmentNotStarted($request, $type, $refusal);
        }

        $credentialType = $this->enrollable($types, $type);

        if ($credentialType === null) {
            return $this->refuseUnofferedType();
        }

        try {
            $running = (new EnrollmentCeremonies(Keystone::guard()))->resolve($credentialType, $account);
        } catch (Throwable $e) {
            report($e);

            return $this->sendCredentialEnrollmentNotStarted($request, $credentialType->name(), __('keystone::messages.enrollment_failed'));
        }

        $page = new EnrollmentFormPage(
            type: $credentialType->name(),
            shape: $credentialType->surfaces()[Surface::ENROLLMENT->value]->value,
            ceremony: $running->page,
            status: Status::flashed($request)?->label(),
            held: $this->held($account, $credentialType),
        );

        return $this->sendCredentialEnrollmentForm($request, $page);
    }

    /**
     * Answer the type's enrollment ceremony, storing the new credential on the signed-in account.
     */
    public function store(Request $request, string $type): Response|Responsable
    {
        (new SudoGate(Keystone::guard()))->enforce($request);

        $types = app(CredentialTypes::class);
        $refusal = $types->enrollmentRefusal($type);

        if ($refusal !== null) {
            return $this->sendCredentialEnrollmentNotStarted($request, $type, $refusal);
        }

        $credentialType = $this->enrollable($types, $type);

        if ($credentialType === null) {
            return $this->refuseUnofferedType();
        }

        $running = (new EnrollmentCeremonies(Keystone::guard()))->running($credentialType);

        if ($running === null) {
            return $this->refuseExpiredCeremony($request, $credentialType->name());
        }

        $validator = Validator::make($request->all(), $credentialType->rules(Surface::ENROLLMENT));

        if ($validator->fails()) {
            return redirect()->route('security.enroll', ['type' => $credentialType->name()])->withErrors($validator->errors());
        }

        $result = (new SettingsEnrollmentAttempt(Keystone::guard(), new RateLimiter($request, app(RequestContext::class), Keystone::guard())))
            ->attempt($credentialType, $validator->validated(), $running);

        return match ($result) {
            SettingsEnrollmentResult::ADDED => $this->enrolled($request, Status::ENROLLED),
            SettingsEnrollmentResult::REPLACED => $this->enrolled($request, $types->replacedStatus($credentialType)),
            SettingsEnrollmentResult::REFUSED => $this->sendCredentialEnrollmentRefused($request, $credentialType->name(), __('keystone::messages.invalid_credential')),
            SettingsEnrollmentResult::SUDO_ENDED => $this->refuseWithoutSudo($request, $credentialType->name()),
        };
    }

    /**
     * Cancel the type's enrollment ceremony, forgetting what it made.
     *
     * It needs no sudo, because it only closes a ceremony.
     */
    public function destroy(Request $request, string $type): Response|Responsable
    {
        if (! Keystone::guard()->check()) {
            return redirect()->route('login');
        }

        $credentialType = $this->enrollable(app(CredentialTypes::class), $type);

        if ($credentialType !== null) {
            (new EnrollmentCeremonies(Keystone::guard()))->close($credentialType);
        }

        return $this->sendCredentialEnrollmentCancelled($request);
    }

    /**
     * Respond with the type's enrollment form, showing what its ceremony needs.
     */
    abstract protected function sendCredentialEnrollmentForm(Request $request, EnrollmentFormPage $page): Response|Responsable;

    /**
     * Respond to a type whose enrollment ceremony couldn't start, with the message for the type.
     */
    abstract protected function sendCredentialEnrollmentNotStarted(Request $request, string $type, string $message): Response|Responsable;

    /**
     * Respond to a refused enrollment answer, with the message for the type's field.
     */
    abstract protected function sendCredentialEnrollmentRefused(Request $request, string $type, string $message): Response|Responsable;

    /**
     * Respond to an answer whose ceremony expired, sending the user back to start the type's enrollment afresh.
     */
    abstract protected function sendCredentialEnrollmentExpired(Request $request, string $type): Response|Responsable;

    /**
     * Respond to a stored credential.
     */
    abstract protected function sendCredentialEnrolled(Request $request): Response|Responsable;

    /**
     * Respond to a cancelled enrollment.
     */
    abstract protected function sendCredentialEnrollmentCancelled(Request $request): Response|Responsable;

    /**
     * Get the named type when keystone.methods lists it on enrollment.
     */
    protected function enrollable(CredentialTypes $types, string $name): ?CredentialType
    {
        return $types->find($name, Surface::ENROLLMENT);
    }

    /**
     * Get the account's usable credentials of the type, and whether removing each would be allowed.
     *
     * @return list<array{id: int, label: ?string, removable: bool}>
     */
    protected function held(Model&KeystoneUser $account, CredentialType $type): array
    {
        $credentials = new Credentials($account);
        $held = array_filter($credentials->ofAccount($account->getKey()), fn (array $credential) => $credential['type'] === $type->name() && ! $credential['disabled']);
        $refusals = $credentials->removalRefusals($account->getKey());

        return array_values(array_map(fn (array $credential) => [
            'id' => $credential['id'],
            'label' => $credential['label'],
            'removable' => ! isset($refusals[$credential['id']]),
        ], $held));
    }

    /**
     * Send a request for a type the user can't enroll back to the security page.
     */
    protected function refuseUnofferedType(): RedirectResponse
    {
        return redirect()->route('security');
    }

    /**
     * Send the user back from an answer whose ceremony is gone, saying so.
     */
    protected function refuseExpiredCeremony(Request $request, string $type): Response|Responsable
    {
        Status::ENROLLMENT_EXPIRED->flash($request);

        return $this->sendCredentialEnrollmentExpired($request, $type);
    }

    /**
     * Send the user on from a stored credential, saying so with the status.
     */
    protected function enrolled(Request $request, Status $status): Response|Responsable
    {
        $status->flash($request);

        return $this->sendCredentialEnrolled($request);
    }

    /**
     * Refuse an answer whose session lost its sudo or its sign-in while it was checked, as the gate does, so the user comes back to the type's step once sudo is granted.
     */
    protected function refuseWithoutSudo(Request $request, string $type): Response|Responsable
    {
        (new SudoGate(Keystone::guard()))->enforce($request);

        return $this->refuseExpiredCeremony($request, $type);
    }
}
