<?php

namespace ClaudioDekker\Keystone\Http\Controllers;

use ClaudioDekker\Keystone\Http\Concerns\RefusesSignedInUsers;
use ClaudioDekker\Keystone\Http\PageValues\RegistrationFinishPage;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Status;
use ClaudioDekker\Keystone\StepKind;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            static::openRegistration(),
        ];
    }

    /**
     * Show the proven address and the credential types that can finish the registration, or send a session without one back to register.
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
     * Respond with the page that finishes the registration.
     */
    abstract protected function sendRegistrationFinishPage(Request $request, RegistrationFinishPage $page): Response|Responsable;

    /**
     * Send a session that proved no address, or whose window ended, back to register.
     */
    protected function refuseWithoutRegistration(): RedirectResponse
    {
        return redirect()->route('register');
    }

    /**
     * Get the registered credential types.
     */
    protected function types(): CredentialTypes
    {
        return app(CredentialTypes::class);
    }
}
