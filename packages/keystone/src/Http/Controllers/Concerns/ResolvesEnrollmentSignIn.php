<?php

namespace ClaudioDekker\Keystone\Http\Controllers\Concerns;

use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\PendingSignIn;
use ClaudioDekker\Keystone\PendingStage;
use Illuminate\Http\RedirectResponse;

trait ResolvesEnrollmentSignIn
{
    /**
     * Get the session's live sign-in held at enrollment.
     */
    protected function pending(): ?PendingSignIn
    {
        $pending = Keystone::guard()->pending();

        return $pending?->stage === PendingStage::ENROLLMENT ? $pending : null;
    }

    /**
     * Send a session with no sign-in held at enrollment to the sign-in page.
     */
    protected function refuseWithoutEnrollment(): RedirectResponse
    {
        return redirect()->route('login');
    }
}
