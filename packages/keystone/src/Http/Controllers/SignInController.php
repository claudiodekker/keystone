<?php

namespace ClaudioDekker\Keystone\Http\Controllers;

use ClaudioDekker\Keystone\Actions\AccountLookup;
use ClaudioDekker\Keystone\Demand;
use ClaudioDekker\Keystone\Http\Concerns\RefusesSignedInUsers;
use ClaudioDekker\Keystone\Http\PageValues\SignInPage;
use ClaudioDekker\Keystone\IntendedUrl;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\RateLimiter;
use ClaudioDekker\Keystone\RememberMe;
use ClaudioDekker\Keystone\RememberTokens;
use ClaudioDekker\Keystone\RequestContext;
use ClaudioDekker\Keystone\SignInAttempt;
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
abstract class SignInController extends Controller
{
    use RefusesSignedInUsers;

    /**
     * The input field that names the account; the only field ever flashed back.
     */
    public const string IDENTIFIER = 'identifier';

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
     * Show the sign-in page.
     */
    public function show(Request $request): Response|Responsable
    {
        if (Keystone::guard()->check()) {
            return $this->refuseSignedIn();
        }

        $types = array_map(fn (CredentialType $type) => [
            'type' => $type->name(),
            'shape' => $type->surfaces()[Surface::SIGN_IN->value]->value,
        ], $this->types()->serving(Surface::SIGN_IN));

        $page = new SignInPage(
            types: $types,
            status: Status::flashed($request)?->label(),
            rememberOffered: RememberTokens::isOffered(),
        );

        return $this->sendSignInPage($request, $page);
    }

    /**
     * Sign in with a proof of the credential type.
     */
    public function store(Request $request, string $type): Response|Responsable
    {
        if (Keystone::guard()->check()) {
            return $this->refuseSignedIn();
        }

        $credentialType = $this->types()->find($type, Surface::SIGN_IN);

        $typeRules = $credentialType?->rules(Surface::SIGN_IN) ?? [];

        $validator = Validator::make($request->all(), [
            self::IDENTIFIER => ['required', 'string', 'max:255'],
            ...$typeRules,
        ]);

        if ($validator->fails()) {
            return $this->refuseInvalid($request, $validator->errors());
        }

        $input = $validator->validated();
        $identifier = $input[self::IDENTIFIER];
        $proofInput = Arr::except($input, self::IDENTIFIER);

        $intended = $request->session()->get('url.intended');
        $intendedUrl = IntendedUrl::sanitize($intended, (string) config('app.url'));

        $attempt = new SignInAttempt(Keystone::guard(), app(AccountLookup::class), new RateLimiter($request, app(RequestContext::class), Keystone::guard()));
        $demand = $attempt->attempt($credentialType, $identifier, $proofInput, $intendedUrl, RememberMe::fromRequest($request));

        if ($demand === Demand::REFUSE) {
            $this->flashIdentifier($request);

            return $this->sendSignInRefused($request, __('keystone::messages.failed'));
        }

        $request->session()->forget('url.intended');

        if ($demand === Demand::CHALLENGE) {
            return $this->sendChallengeOwed($request);
        }

        if ($demand === Demand::ENROLLMENT) {
            return $this->sendEnrollmentOwed($request);
        }

        return $this->sendSignedIn($request, $intendedUrl);
    }

    /**
     * Respond with the sign-in page.
     */
    abstract protected function sendSignInPage(Request $request, SignInPage $page): Response|Responsable;

    /**
     * Respond to a refused sign-in, with the message for the identifier field.
     */
    abstract protected function sendSignInRefused(Request $request, string $message): Response|Responsable;

    /**
     * Respond to a sign-in held for a challenge, sending the user on to it.
     */
    abstract protected function sendChallengeOwed(Request $request): Response|Responsable;

    /**
     * Respond to a sign-in held until the account enrolls what it owes, sending the user on to enroll it.
     */
    abstract protected function sendEnrollmentOwed(Request $request): Response|Responsable;

    /**
     * Respond to a completed sign-in, sending the user on to the intended URL.
     */
    abstract protected function sendSignedIn(Request $request, string $intendedUrl): Response|Responsable;

    /**
     * Send invalid input back to the sign-in page, flashing only the identifier.
     */
    protected function refuseInvalid(Request $request, MessageBag $errors): RedirectResponse
    {
        $this->flashIdentifier($request);

        return redirect()->route('login')->withErrors($errors);
    }

    /**
     * Flash the typed identifier, and nothing else, back to the sign-in page.
     */
    protected function flashIdentifier(Request $request): void
    {
        $identifier = $request->input(self::IDENTIFIER);

        $request->session()->flashInput(is_string($identifier) ? [self::IDENTIFIER => $identifier] : []);
    }

    /**
     * Get the registered credential types.
     */
    protected function types(): CredentialTypes
    {
        return app(CredentialTypes::class);
    }
}
