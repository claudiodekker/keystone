<?php

namespace ClaudioDekker\Keystone\Http\Concerns;

use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\PendingSignIn;
use ClaudioDekker\Keystone\PendingStage;
use Illuminate\Http\RedirectResponse;

trait ResolvesEnrollmentSignIn
{
    use RefusesSignedInUsers;

    /**
     * Get the session's live sign-in held at enrollment, or the redirect that refuses a request without one.
     */
    protected function held(): PendingSignIn|RedirectResponse
    {
        if (Keystone::guard()->check()) {
            return $this->refuseSignedIn();
        }

        $pending = Keystone::guard()->pending();

        return $pending?->stage === PendingStage::ENROLLMENT ? $pending : $this->refuseWithoutEnrollment();
    }

    /**
     * Send a session with no sign-in held at enrollment to the sign-in page.
     */
    protected function refuseWithoutEnrollment(): RedirectResponse
    {
        return redirect()->route('login');
    }
}
