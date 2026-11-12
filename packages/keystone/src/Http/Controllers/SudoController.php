<?php

namespace ClaudioDekker\Keystone\Http\Controllers;

use ClaudioDekker\Keystone\Http\Middleware\LimitRequests;
use ClaudioDekker\Keystone\Http\Pages\SudoPage;
use ClaudioDekker\Keystone\RateLimits\StepKind;
use ClaudioDekker\Keystone\SignIn\NoSudoInProgress;
use ClaudioDekker\Keystone\SignIn\Sudo;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\Middleware;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @api
 */
abstract class SudoController implements KeystoneController
{
    /**
     * Get the middleware every sudo action runs, whatever the app's subclass overrides.
     *
     * Every action but store() and destroy() counts as a view, so an action the app adds is limited too.
     *
     * @return list<Middleware|string>
     */
    public static function middleware(): array
    {
        return [
            new Middleware(LimitRequests::for(StepKind::CHANGE), only: ['destroy']),
            new Middleware(LimitRequests::for(StepKind::SUBMIT), only: ['store']),
            new Middleware(LimitRequests::for(StepKind::VIEW), except: ['destroy', 'store']),
        ];
    }

    /**
     * Show the step of the session's sudo-in-progress.
     */
    public function show(Request $request, Sudo $sudo): Response|Responsable
    {
        $outcome = $sudo->open();

        if ($outcome instanceof NoSudoInProgress) {
            return $this->sendNoSudoInProgress($request, $outcome->redirectTo);
        }

        return $this->sendSudoPage($request, $outcome);
    }

    /**
     * Take an answer to the sudo-in-progress's step with the credential type the route names.
     */
    public function store(Request $request, string $type, Sudo $sudo): Response|Responsable
    {
        $credentialType = $sudo->type($type) ?? throw new NotFoundHttpException;

        $outcome = $sudo->answer($credentialType, $request->all());

        if ($outcome instanceof NoSudoInProgress) {
            return $this->sendNoSudoInProgress($request, $outcome->redirectTo);
        }

        return $this->sendSudoGranted($request, $outcome->redirectTo);
    }

    /**
     * Render the sudo page from its page value.
     */
    abstract protected function sendSudoPage(Request $request, SudoPage $page): Response|Responsable;

    /**
     * Send a session that holds no sudo-in-progress on to where it was going.
     */
    abstract protected function sendNoSudoInProgress(Request $request, string $redirectTo): Response|Responsable;

    /**
     * Send the user on to where they were going once sudo is granted.
     */
    abstract protected function sendSudoGranted(Request $request, string $redirectTo): Response|Responsable;
}
