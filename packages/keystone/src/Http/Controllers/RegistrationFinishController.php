<?php

namespace ClaudioDekker\Keystone\Http\Controllers;

use ClaudioDekker\Keystone\Actions\CreateAccount;
use ClaudioDekker\Keystone\Http\Concerns\RefusesSignedInUsers;
use ClaudioDekker\Keystone\Http\PageValues\RegistrationFinishPage;
use ClaudioDekker\Keystone\IntendedUrl;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\RegistrationAttempt;
use ClaudioDekker\Keystone\RegistrationResult;
use ClaudioDekker\Keystone\Status;
use ClaudioDekker\Keystone\StepKind;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
abstract class RegistrationFinishController extends Controller
{
    use RefusesSignedInUsers;

    /**
     * Get the middleware that runs before the controller's actions.
     */
    public static function middleware(): array
    {
        return [
            static::throttle(StepKind::VIEW, 'show'),
            static::throttle(StepKind::SUBMIT, 'store'),
            static::throttle(StepKind::CHANGE, 'destroy'),
            static::openRegistration(),
        ];
    }

    /**
     * Show the registering address and the credential types that can finish the registration, or send a session without one back to register.
     */
    public function show(Request $request): Response|Responsable
    {
        if (Keystone::guard()->check()) {
            return $this->refuseSignedIn();
        }

        $registration = Keystone::guard()->registration();

        if ($registration === null) {
            return $this->refuseWithoutRegistration();
        }

        return $this->sendRegistrationFinishPage($request, new RegistrationFinishPage(
            address: $registration->address,
            types: $this->typeOptions($this->types()->serving(Surface::REGISTRATION), Surface::REGISTRATION),
            status: Status::flashed($request)?->label(),
        ));
    }

    /**
     * Create the account holding the registering address and a credential of the type, then sign it in or send it on to the enrollment it owes.
     */
    public function store(Request $request, string $type): Response|Responsable
    {
        if (Keystone::guard()->check()) {
            return $this->refuseSignedIn();
        }

        if (Keystone::guard()->registration() === null) {
            return $this->refuseExpiredRegistration($request);
        }

        $credentialType = $this->types()->find($type, Surface::REGISTRATION);

        if ($credentialType === null) {
            return $this->refuseUnservedType();
        }

        $createAccount = app(CreateAccount::class);
        $profileFields = array_keys($createAccount->rules());

        $validator = Validator::make($request->all(), [
            ...$createAccount->rules(),
            ...$credentialType->rules(Surface::REGISTRATION),
        ]);

        if ($validator->fails()) {
            return $this->refuseInvalid($request, $profileFields, $validator->errors());
        }

        $input = $validator->validated();
        $intendedUrl = IntendedUrl::sanitize($request->session()->get('url.intended'), (string) config('app.url'));

        $attempt = new RegistrationAttempt(Keystone::guard(), $createAccount);
        $result = $attempt->attempt($credentialType, Arr::only($input, $profileFields), Arr::except($input, $profileFields), $intendedUrl);

        if ($result === RegistrationResult::SIGNED_IN || $result === RegistrationResult::ENROLLMENT_OWED) {
            $request->session()->forget('url.intended');
        }

        return match ($result) {
            RegistrationResult::SIGNED_IN => $this->sendRegistered($request, $intendedUrl),
            RegistrationResult::ENROLLMENT_OWED => $this->sendRegistrationEnrollmentOwed($request),
            RegistrationResult::REFUSED => $this->refuseCredential($request, $profileFields, $credentialType->name()),
            RegistrationResult::ADDRESS_TAKEN => $this->refuseTakenAddress($request),
            RegistrationResult::BARRED => $this->sendRegistrationBarred($request, __('keystone::messages.failed')),
            RegistrationResult::EXPIRED => $this->refuseExpiredRegistration($request),
        };
    }

    /**
     * Cancel the registration before the account exists, forgetting the registering address and closing every ceremony slot.
     */
    public function destroy(Request $request): Response|Responsable
    {
        if (Keystone::guard()->check()) {
            return $this->refuseSignedIn();
        }

        if (Keystone::guard()->registration() !== null) {
            Keystone::guard()->endRegistration();
        }

        Status::REGISTRATION_CANCELLED->flash($request);

        return $this->sendRegistrationCancelled($request);
    }

    /**
     * Respond with the page that finishes the registration.
     */
    abstract protected function sendRegistrationFinishPage(Request $request, RegistrationFinishPage $page): Response|Responsable;

    /**
     * Respond to a registration that created the account and signed it in, sending the user on to the intended URL.
     */
    abstract protected function sendRegistered(Request $request, string $intendedUrl): Response|Responsable;

    /**
     * Respond to a registration that created the account and held its sign-in for the enrollment it owes, sending the user on to enroll it.
     */
    abstract protected function sendRegistrationEnrollmentOwed(Request $request): Response|Responsable;

    /**
     * Respond to a credential the type refused, with the message for the type, keeping the registration.
     */
    abstract protected function sendRegistrationRefused(Request $request, string $type, string $message): Response|Responsable;

    /**
     * Respond to an address an active account came to hold, which ended the registration and created nothing.
     */
    abstract protected function sendAddressTaken(Request $request): Response|Responsable;

    /**
     * Respond to an account the app's action created barred from signing in, with the message a refused sign-in gets; the registration has ended.
     */
    abstract protected function sendRegistrationBarred(Request $request, string $message): Response|Responsable;

    /**
     * Respond to a cancelled registration.
     */
    abstract protected function sendRegistrationCancelled(Request $request): Response|Responsable;

    /**
     * Send a session that proved no address, or whose window ended, back to register.
     */
    protected function refuseWithoutRegistration(): RedirectResponse
    {
        return redirect()->route('register');
    }

    /**
     * Send a finish whose session proved no address, or whose window ended, back to register, saying the registration expired.
     */
    protected function refuseExpiredRegistration(Request $request): RedirectResponse
    {
        Status::REGISTRATION_EXPIRED->flash($request);

        return $this->refuseWithoutRegistration();
    }

    /**
     * Send a request for a type that doesn't serve registration back to the finish page.
     */
    protected function refuseUnservedType(): RedirectResponse
    {
        return redirect()->route('register.finish');
    }

    /**
     * Send invalid input back to the finish page, flashing only the account's fields.
     *
     * @param  list<string>  $profileFields
     */
    protected function refuseInvalid(Request $request, array $profileFields, MessageBag $errors): RedirectResponse
    {
        $this->flashProfile($request, $profileFields);

        return redirect()->route('register.finish')->withErrors($errors);
    }

    /**
     * Refuse the credential the type didn't make, flashing only the account's fields.
     *
     * @param  list<string>  $profileFields
     */
    protected function refuseCredential(Request $request, array $profileFields, string $type): Response|Responsable
    {
        $this->flashProfile($request, $profileFields);

        return $this->sendRegistrationRefused($request, $type, __('keystone::messages.invalid_credential'));
    }

    /**
     * Refuse an address an active account came to hold, saying it is already registered.
     */
    protected function refuseTakenAddress(Request $request): Response|Responsable
    {
        Status::ADDRESS_ALREADY_REGISTERED->flash($request);

        return $this->sendAddressTaken($request);
    }

    /**
     * Flash the typed account fields, and nothing else, back to the finish page.
     *
     * @param  list<string>  $profileFields
     */
    protected function flashProfile(Request $request, array $profileFields): void
    {
        $request->session()->flashInput(array_filter($request->only($profileFields), is_string(...)));
    }

    /**
     * Get the registered credential types.
     */
    protected function types(): CredentialTypes
    {
        return app(CredentialTypes::class);
    }
}
