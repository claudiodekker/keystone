<?php

namespace ClaudioDekker\Keystone\Http\Controllers;

use ClaudioDekker\Keystone\EmailedLinks;
use ClaudioDekker\Keystone\Http\Concerns\RefusesSignedInUsers;
use ClaudioDekker\Keystone\Http\PageValues\EmailedLinkPage;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\RegistrationLink;
use ClaudioDekker\Keystone\StepKind;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
abstract class RegistrationLinkController extends Controller
{
    use RefusesSignedInUsers;

    /**
     * Get the middleware that runs before the controller's actions.
     */
    public static function middleware(): array
    {
        return [
            static::noReferrer('show', 'store'),
            static::throttle(StepKind::VIEW, 'show', 'expired'),
            static::throttle(StepKind::SUBMIT, 'store'),
            static::openRegistration(),
        ];
    }

    /**
     * Show the mailed link's step, whose one button spends it, changing nothing; a link that no longer works goes to "link expired".
     */
    public function show(Request $request): Response|Responsable
    {
        if (Keystone::guard()->check()) {
            return $this->refuseSignedIn();
        }

        if ((new EmailedLinks(Keystone::guard()))->open($request, RegistrationLink::class) === null) {
            return $this->sendRegistrationLinkExpired($request);
        }

        return $this->sendRegistrationLinkPage($request, new EmailedLinkPage(
            action: route('register.verify.consume', Arr::only($request->query(), ['expires', 'token', 'signature']), absolute: false),
        ));
    }

    /**
     * Spend the link and start registering the address it proved on a new session id, or send every failure alike to "link expired".
     */
    public function store(Request $request): Response|Responsable
    {
        if (Keystone::guard()->check()) {
            return $this->refuseSignedIn();
        }

        $link = (new EmailedLinks(Keystone::guard()))->consume($request, RegistrationLink::class);

        if ($link === null) {
            return $this->sendRegistrationLinkExpired($request);
        }

        Keystone::guard()->startRegistration($link->address);

        return $this->sendRegistrationLinkConsumed($request);
    }

    /**
     * Show the step saying the link no longer works.
     */
    public function expired(Request $request): Response|Responsable
    {
        if (Keystone::guard()->check()) {
            return $this->refuseSignedIn();
        }

        return $this->sendRegistrationLinkExpiredPage($request);
    }

    /**
     * Respond with the mailed link's step, whose one button posts to the page value's action.
     */
    abstract protected function sendRegistrationLinkPage(Request $request, EmailedLinkPage $page): Response|Responsable;

    /**
     * Respond to a spent link, sending the user on to finish registering.
     */
    abstract protected function sendRegistrationLinkConsumed(Request $request): Response|Responsable;

    /**
     * Respond to a link that no longer works, for whatever reason, sending the user on to "link expired".
     */
    abstract protected function sendRegistrationLinkExpired(Request $request): Response|Responsable;

    /**
     * Respond with the step saying the link no longer works.
     */
    abstract protected function sendRegistrationLinkExpiredPage(Request $request): Response|Responsable;
}
