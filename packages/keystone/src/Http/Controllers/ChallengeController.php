<?php

namespace ClaudioDekker\Keystone\Http\Controllers;

use ClaudioDekker\Keystone\ChallengeAttempt;
use ClaudioDekker\Keystone\CredentialAttempt;
use ClaudioDekker\Keystone\Http\PageValues\ChallengePage;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\PendingSignIn;
use ClaudioDekker\Keystone\PendingStage;
use ClaudioDekker\Keystone\RateLimiter;
use ClaudioDekker\Keystone\SignInDecision;
use ClaudioDekker\Keystone\Status;
use ClaudioDekker\Keystone\StepKind;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Timebox;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
abstract class ChallengeController extends Controller
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
     * Show the challenge page, offering the second factors the held account can answer with, inside the timing floor.
     */
    public function show(Request $request): Response|Responsable
    {
        return (new Timebox)->call(fn () => $this->offer($request), CredentialAttempt::TIMING_FLOOR_MICROSECONDS);
    }

    /**
     * Answer the challenge with a proof of the credential type, completing the sign-in.
     */
    public function store(Request $request, string $type): Response|Responsable
    {
        if (Keystone::guard()->check()) {
            return $this->refuseSignedIn();
        }

        $pending = $this->pending();

        if ($pending === null) {
            return $this->refuseWithoutChallenge();
        }

        $credentialType = $this->types()->find($type, Surface::CHALLENGE);

        if ($credentialType === null) {
            return $this->sendChallengeRefused($request, $type, __('keystone::messages.invalid_credential'));
        }

        $validator = Validator::make($request->all(), $credentialType->rules(Surface::CHALLENGE));

        if ($validator->fails()) {
            return redirect()->route('login.challenge')->withErrors($validator->errors());
        }

        $attempt = new ChallengeAttempt(Keystone::guard(), new RateLimiter($request, Keystone::guard()));

        if (! $attempt->attempt($pending, $credentialType, $validator->validated())) {
            return $this->sendChallengeRefused($request, $credentialType->name(), __('keystone::messages.invalid_credential'));
        }

        return $this->sendChallengePassed($request, $pending->intendedUrl);
    }

    /**
     * Cancel the pending sign-in, leaving a guest.
     */
    public function destroy(Request $request): Response|Responsable
    {
        if (Keystone::guard()->check()) {
            return $this->refuseSignedIn();
        }

        if ($this->pending() === null) {
            return $this->refuseWithoutChallenge();
        }

        Keystone::guard()->forgetPending();

        Status::SIGN_IN_CANCELLED->flash($request);

        return $this->sendChallengeCancelled($request);
    }

    /**
     * Respond with the challenge page.
     */
    abstract protected function sendChallengePage(Request $request, ChallengePage $page): Response|Responsable;

    /**
     * Respond to a held account that holds a second factor no listed type can answer, sending the user on to recover it.
     */
    abstract protected function sendSecondFactorUnavailable(Request $request): Response|Responsable;

    /**
     * Respond to a refused answer, with the message for the credential type's field.
     */
    abstract protected function sendChallengeRefused(Request $request, string $type, string $message): Response|Responsable;

    /**
     * Respond to a passed challenge, sending the signed-in user on to the intended URL.
     */
    abstract protected function sendChallengePassed(Request $request, string $intendedUrl): Response|Responsable;

    /**
     * Respond to a cancelled challenge.
     */
    abstract protected function sendChallengeCancelled(Request $request): Response|Responsable;

    /**
     * Offer the held account's second factors, or send the user on when it has none to offer.
     */
    protected function offer(Request $request): Response|Responsable
    {
        if (Keystone::guard()->check()) {
            return $this->refuseSignedIn();
        }

        $pending = $this->pending();

        if ($pending === null) {
            return $this->refuseWithoutChallenge();
        }

        $decision = new SignInDecision;
        $offer = $decision->challengeOffer($pending->account, $pending->firstFactor);

        if ($offer === [] && ! $decision->holdsSecondFactor($pending->account, $pending->firstFactor)) {
            Keystone::guard()->forgetPending();

            return $this->refuseWithoutChallenge();
        }

        if ($offer === []) {
            Status::SECOND_FACTOR_UNAVAILABLE->flash($request);

            return $this->sendSecondFactorUnavailable($request);
        }

        $types = array_map(fn (CredentialType $type) => [
            'type' => $type->name(),
            'shape' => $type->surfaces()[Surface::CHALLENGE->value]->value,
        ], $offer);

        $page = new ChallengePage(
            types: $types,
            preselect: $types[0]['type'],
        );

        return $this->sendChallengePage($request, $page);
    }

    /**
     * Get the session's live sign-in held at the challenge.
     */
    protected function pending(): ?PendingSignIn
    {
        $pending = Keystone::guard()->pending();

        return $pending?->stage === PendingStage::CHALLENGE ? $pending : null;
    }

    /**
     * Send a signed-in user away from the challenge.
     */
    protected function refuseSignedIn(): RedirectResponse
    {
        return redirect('/');
    }

    /**
     * Send a session with no sign-in held at the challenge to the sign-in page.
     */
    protected function refuseWithoutChallenge(): RedirectResponse
    {
        return redirect()->route('login');
    }

    /**
     * Get the registered credential types.
     */
    protected function types(): CredentialTypes
    {
        return app(CredentialTypes::class);
    }
}
