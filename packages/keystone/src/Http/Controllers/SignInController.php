<?php

namespace ClaudioDekker\Keystone\Http\Controllers;

use ClaudioDekker\Keystone\Actions\AccountLookup;
use ClaudioDekker\Keystone\Http\PageValues\SignInPage;
use ClaudioDekker\Keystone\IntendedUrl;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\SignInAttempt;
use ClaudioDekker\Keystone\Status;
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
        if (Keystone::guard()->check()) {
            return $this->refuseSignedIn();
        }

        return $this->sendSignInPage($request, new SignInPage(
            types: array_map(fn (CredentialType $type) => [
                'type' => $type->name(),
                'shape' => $type->surfaces()[Surface::SIGN_IN->value]->value,
            ], $this->types()->serving(Surface::SIGN_IN)),
            status: Status::flashed($request)?->label(),
        ));
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

        $validator = Validator::make($request->all(), [
            self::IDENTIFIER => ['required', 'string', 'max:255'],
            ...$credentialType?->rules(Surface::SIGN_IN) ?? [],
        ]);

        if ($validator->fails()) {
            return $this->refuseInvalid($request, $validator->errors());
        }

        $input = $validator->validated();
        $account = (new SignInAttempt(Keystone::guard(), app(AccountLookup::class)))
            ->attempt($credentialType, $input[self::IDENTIFIER], Arr::except($input, self::IDENTIFIER));

        if ($account === null) {
            $this->flashIdentifier($request);

            return $this->sendSignInRefused($request, __('keystone::messages.failed'));
        }

        return $this->sendSignedIn($request, IntendedUrl::sanitize(
            $request->session()->pull('url.intended'),
            (string) config('app.url'),
        ));
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
