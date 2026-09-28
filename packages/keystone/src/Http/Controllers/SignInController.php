<?php

namespace ClaudioDekker\Keystone\Http\Controllers;

use ClaudioDekker\Keystone\Actions\AccountLookup;
use ClaudioDekker\Keystone\Http\PageValues\SignInPage;
use ClaudioDekker\Keystone\IntendedUrl;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\RateLimiter;
use ClaudioDekker\Keystone\SignInAttempt;
use ClaudioDekker\Keystone\Status;
use ClaudioDekker\Keystone\StepKind;
use ClaudioDekker\Keystone\Throttled;
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
abstract class SignInController
{
    /**
     * The input field that names the account; the only field ever flashed back.
     */
    public const string IDENTIFIER = 'identifier';

    /**
     * Show the sign-in page.
     */
    public function show(Request $request): Response|Responsable
    {
        try {
            $this->limiter($request)->hitRequest(StepKind::VIEW);
        } catch (Throttled $throttled) {
            return $this->refuseThrottled($request, $throttled->retryAfterSeconds);
        }

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
        );

        return $this->sendSignInPage($request, $page);
    }

    /**
     * Sign in with a proof of the credential type.
     */
    public function store(Request $request, string $type): Response|Responsable
    {
        try {
            return $this->signIn($request, $type);
        } catch (Throttled $throttled) {
            return $this->refuseThrottled($request, $throttled->retryAfterSeconds);
        }
    }

    /**
     * Run the sign-in behind its rate limits.
     *
     * @throws Throttled
     */
    protected function signIn(Request $request, string $type): Response|Responsable
    {
        $this->limiter($request)->hitRequest(StepKind::SUBMIT);

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

        $guard = Keystone::guard();
        $lookup = app(AccountLookup::class);
        $limiter = $this->limiter($request);
        $attempt = new SignInAttempt($guard, $lookup, $limiter);
        $account = $attempt->attempt($credentialType, $identifier, $proofInput);

        if ($account === null) {
            $this->flashIdentifier($request);

            return $this->sendSignInRefused($request, __('keystone::messages.failed'));
        }

        $intended = $request->session()->pull('url.intended');
        $intendedUrl = IntendedUrl::sanitize($intended, (string) config('app.url'));

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
     * Respond to a completed sign-in, sending the user on to the intended URL.
     */
    abstract protected function sendSignedIn(Request $request, string $intendedUrl): Response|Responsable;

    /**
     * Send a signed-in user away from a guest step.
     */
    protected function refuseSignedIn(): RedirectResponse
    {
        return redirect('/');
    }

    /**
     * Refuse a step whose rate limit is spent, saying when to try again.
     */
    protected function refuseThrottled(Request $request, int $retryAfterSeconds): Response
    {
        $message = __('keystone::messages.throttled', ['seconds' => $retryAfterSeconds]);

        return response($message, Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => $retryAfterSeconds]);
    }

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
     * Get core's rate limiter for the request.
     */
    protected function limiter(Request $request): RateLimiter
    {
        return new RateLimiter($request, Keystone::guard());
    }

    /**
     * Get the registered credential types.
     */
    protected function types(): CredentialTypes
    {
        return app(CredentialTypes::class);
    }
}
