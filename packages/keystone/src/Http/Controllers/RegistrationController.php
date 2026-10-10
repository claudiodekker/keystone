<?php

namespace ClaudioDekker\Keystone\Http\Controllers;

use ClaudioDekker\Keystone\EmailedLinks;
use ClaudioDekker\Keystone\Http\Concerns\RefusesSignedInUsers;
use ClaudioDekker\Keystone\Http\PageValues\RegisterPage;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\RateLimiter;
use ClaudioDekker\Keystone\RegistrationRequestAttempt;
use ClaudioDekker\Keystone\RequestContext;
use ClaudioDekker\Keystone\Status;
use ClaudioDekker\Keystone\StepKind;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
abstract class RegistrationController extends Controller
{
    use RefusesSignedInUsers;

    /**
     * The input field holding the typed email address; the only field ever flashed back.
     */
    public const string EMAIL = 'email';

    /**
     * Get the middleware that runs before the controller's actions.
     */
    public static function middleware(): array
    {
        return [
            static::throttle(StepKind::VIEW, 'show', 'sent'),
            static::throttle(StepKind::START, 'store'),
            static::openRegistration(),
        ];
    }

    /**
     * Show the register page.
     */
    public function show(Request $request): Response|Responsable
    {
        if (Keystone::guard()->check()) {
            return $this->refuseSignedIn();
        }

        return $this->sendRegistrationPage($request, new RegisterPage(status: Status::flashed($request)?->label()));
    }

    /**
     * Mail a registration link to the typed address, or alert the accounts holding it, and send the user on to "link sent" either way.
     */
    public function store(Request $request): Response|Responsable
    {
        if (Keystone::guard()->check()) {
            return $this->refuseSignedIn();
        }

        $validator = Validator::make($request->all(), [
            self::EMAIL => ['required', 'string', 'email:rfc,strict', 'max:255'],
        ]);

        if ($validator->fails()) {
            return $this->refuseInvalid($request, $validator->errors());
        }

        $attempt = new RegistrationRequestAttempt(Keystone::guard(), new RateLimiter($request, app(RequestContext::class), Keystone::guard()), new EmailedLinks(Keystone::guard()));

        $attempt->attempt($validator->validated()[self::EMAIL]);

        return $this->sendRegistrationLinkSent($request);
    }

    /**
     * Show the step telling the user to check their inbox.
     */
    public function sent(Request $request): Response|Responsable
    {
        if (Keystone::guard()->check()) {
            return $this->refuseSignedIn();
        }

        return $this->sendRegistrationLinkSentPage($request);
    }

    /**
     * Respond with the register page.
     */
    abstract protected function sendRegistrationPage(Request $request, RegisterPage $page): Response|Responsable;

    /**
     * Respond to a handled email address, the same whether it was free, taken or spent its deliveries, sending the user on to "link sent".
     */
    abstract protected function sendRegistrationLinkSent(Request $request): Response|Responsable;

    /**
     * Respond with the step telling the user to check their inbox.
     */
    abstract protected function sendRegistrationLinkSentPage(Request $request): Response|Responsable;

    /**
     * Send invalid input back to the register page, flashing only the typed email address.
     */
    protected function refuseInvalid(Request $request, MessageBag $errors): RedirectResponse
    {
        $email = $request->input(self::EMAIL);

        $request->session()->flashInput(is_string($email) ? [self::EMAIL => $email] : []);

        return redirect()->route('register')->withErrors($errors);
    }
}
