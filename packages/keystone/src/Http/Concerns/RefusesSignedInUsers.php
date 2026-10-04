<?php

namespace ClaudioDekker\Keystone\Http\Concerns;

use Illuminate\Http\RedirectResponse;

trait RefusesSignedInUsers
{
    /**
     * Send a signed-in user away from a step that only a held sign-in takes.
     */
    protected function refuseSignedIn(): RedirectResponse
    {
        return redirect('/');
    }
}
