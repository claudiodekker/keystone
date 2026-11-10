<?php

namespace ClaudioDekker\Keystone\Http\Controllers;

use ClaudioDekker\Keystone\Http\Middleware\FlashOnlyTheIdentifier;
use ClaudioDekker\Keystone\Http\Middleware\LimitRequests;
use ClaudioDekker\Keystone\Http\Pages\ChallengePage;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\RateLimits\StepKind;
use ClaudioDekker\Keystone\SignIn\Challenge;
use ClaudioDekker\Keystone\SignIn\NoPendingSignIn;
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
abstract class ChallengeController implements KeystoneController
{
    /**
     * Get the middleware every challenge action runs, whatever the app's subclass overrides.
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
     * Show the challenge of the session's pending sign-in.
     */
    public function show(Request $request, Challenge $challenge): Response|Responsable
    {
        $outcome = $challenge->open();

        if ($outcome instanceof NoPendingSignIn) {
            return $this->sendNoPendingSignIn($request);
        }

        return $this->sendChallengePage($request, $outcome);
    }

    /**
     * Take an answer to the challenge of the credential type the route names.
     */
    public function store(Request $request, string $type, Challenge $challenge, CredentialTypes $types): Response|Responsable
    {
        $credentialType = $types->find($type, Surface::CHALLENGE) ?? throw new NotFoundHttpException;

        $outcome = $challenge->answer($credentialType, $request->all());

        if ($outcome instanceof NoPendingSignIn) {
            return $this->sendNoPendingSignIn($request);
        }

        if ($outcome instanceof Refused) {
            return $this->sendChallengeRefused($request, $this->field($credentialType), Status::CREDENTIAL_INVALID->label());
        }

        return $this->sendSignedIn($request, $outcome->redirectTo);
    }

    /**
     * Cancel the session's pending sign-in, so the user signs in again from the start.
     */
    public function destroy(Request $request, Challenge $challenge): Response|Responsable
    {
        $challenge->cancel();

        return $this->sendChallengeCancelled($request);
    }

    /**
     * Get the field a refusal of the type's answer is shown on: the first the type validates, else its name.
     */
    protected function field(CredentialType $type): string
    {
        return array_key_first($type->rules(Surface::CHALLENGE)) ?? $type->name();
    }

    /**
     * Render the challenge page from its page value.
     */
    abstract protected function sendChallengePage(Request $request, ChallengePage $page): Response|Responsable;

    /**
     * Send a session that holds no pending sign-in at the challenge to sign in.
     */
    abstract protected function sendNoPendingSignIn(Request $request): Response|Responsable;

    /**
     * Refuse the answer with the translated message on the type's field, keeping the pending sign-in.
     */
    abstract protected function sendChallengeRefused(Request $request, string $field, string $message): Response|Responsable;

    /**
     * Send the user on after the challenge completed the sign-in.
     */
    abstract protected function sendSignedIn(Request $request, string $redirectTo): Response|Responsable;

    /**
     * Send the user to sign in again after cancelling the pending sign-in.
     */
    abstract protected function sendChallengeCancelled(Request $request): Response|Responsable;
}
