<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Exceptions\Throttled;
use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Support\Timebox;

/**
 * @internal
 */
class RegistrationRequestAttempt
{
    /**
     * Create a new registration request attempt instance.
     */
    public function __construct(
        protected KeystoneGuard $guard,
        protected RateLimiter $limiter,
        protected EmailedLinks $links,
        protected Timebox $timebox = new Timebox,
    ) {
        //
    }

    /**
     * Mail the typed address a registration link, or alert the accounts that already hold it, taking the whole timing floor either way.
     */
    public function attempt(string $typed): void
    {
        $this->timebox->call(fn () => $this->request(Addresses::normalize($typed)), CredentialAttempt::TIMING_FLOOR_MICROSECONDS);
    }

    /**
     * Take a delivery for the address, whoever holds it, then mail it the link, or alert the accounts that hold it instead.
     */
    protected function request(string $address): void
    {
        try {
            $this->limiter->takeDelivery(Flow::of($this->guard, Surface::REGISTRATION), RegistrationLink::purpose(), account: null, address: $address);
        } catch (Throttled) {
            return;
        }

        rescue(fn () => $this->links->mail(new RegistrationLink($address), $address));
    }
}
