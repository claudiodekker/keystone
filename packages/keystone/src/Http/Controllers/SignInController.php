<?php

namespace ClaudioDekker\Keystone\Http\Controllers;

use ClaudioDekker\Keystone\Http\Middleware\FlashOnlyTheIdentifier;
use ClaudioDekker\Keystone\Http\Middleware\LimitRequests;
use ClaudioDekker\Keystone\Http\Pages\SignInPage;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\RateLimits\StepKind;
use ClaudioDekker\Keystone\Session\SessionState;
use ClaudioDekker\Keystone\SignIn\Refused;
use ClaudioDekker\Keystone\SignIn\SignIn;
use ClaudioDekker\Keystone\Status;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\Middleware;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @api
 */
abstract class SignInController implements KeystoneController
{
    /**
     * Get the middleware every sign-in action runs, whatever the app's subclass overrides.
     *
     * Every action but store() counts as a view, so an action the app adds is limited too.
     *
     * @return list<Middleware|string>
     */
    public static function middleware(): array
    {
        return [
            FlashOnlyTheIdentifier::class,
            new Middleware(LimitRequests::for(StepKind::SUBMIT), only: ['store']),
            new Middleware(LimitRequests::for(StepKind::VIEW), except: ['store']),
        ];
    }

    /**
     * Show the sign-in page to a guest.
     */
    public function show(Request $request, SessionState $session): Response|Responsable
    {
        if ($session->isSignedIn()) {
            return $this->sendAlreadySignedIn($request);
        }

        $page = new SignInPage(
            types: Keystone::credentialTypes()->shapes(Surface::SIGN_IN),
            status: $session->status()?->label(),
        );

        return $this->sendSignInPage($request, $page);
    }

    /**
     * Take a guest's proof of the credential type the route names.
     */
    public function store(Request $request, string $type, SessionState $session, SignIn $signIn): Response|Responsable
    {
        if ($session->isSignedIn()) {
            return $this->sendAlreadySignedIn($request);
        }

        $credentialType = Keystone::credentialTypes()->find($type, Surface::SIGN_IN) ?? throw new NotFoundHttpException;

        $outcome = $signIn->attempt($credentialType, $request->all());

        if ($outcome instanceof Refused) {
            return $this->sendSignInRefused($request, Status::CREDENTIALS_REJECTED->label());
        }

        return $this->sendSignedIn($request, $outcome->redirectTo);
    }

    /**
     * Render the sign-in page from its page value.
     */
    abstract protected function sendSignInPage(Request $request, SignInPage $page): Response|Responsable;

    /**
     * Refuse a signed-in user at a guest step.
     */
    abstract protected function sendAlreadySignedIn(Request $request): Response|Responsable;

    /**
     * Send the user on after a completed sign-in.
     */
    abstract protected function sendSignedIn(Request $request, string $redirectTo): Response|Responsable;

    /**
     * Refuse the sign-in with the one translated message every refusal shares.
     */
    abstract protected function sendSignInRefused(Request $request, string $message): Response|Responsable;
}
