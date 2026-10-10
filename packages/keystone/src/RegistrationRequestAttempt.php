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
    public function requestLink(string $typed): void
    {
        $this->timebox->call(fn () => $this->mailLink(Addresses::normalize($typed)), CredentialAttempt::TIMING_FLOOR_MICROSECONDS);
    }

    /**
     * Start registering the typed address unverified, alerting the accounts that already hold it, taking the whole timing floor either way.
     */
    public function startUnverified(string $typed): void
    {
        $this->timebox->call(function () use ($typed) {
            $address = Addresses::normalize($typed);

            $this->alertHolders($address);

            $this->guard->startUnverifiedRegistration($address);
        }, CredentialAttempt::TIMING_FLOOR_MICROSECONDS);
    }

    /**
     * Take a delivery for the address, whoever holds it, then mail it the link, or alert the accounts that hold it instead.
     */
    protected function mailLink(string $address): void
    {
        if (! $this->takeDelivery($address)) {
            return;
        }

        rescue(fn () => $this->links->mail(new RegistrationLink($address), $address));
    }

    /**
     * Take a delivery for the address, whoever holds it, then alert the accounts that hold it, mailing the address nothing.
     */
    protected function alertHolders(string $address): void
    {
        if (! $this->takeDelivery($address)) {
            return;
        }

        rescue(fn () => (new AddressClaims($this->guard))->record($address, Flow::of($this->guard, Surface::REGISTRATION)));
    }

    /**
     * Take a delivery for the address, answering false when it spent them.
     */
    protected function takeDelivery(string $address): bool
    {
        try {
            $this->limiter->takeDelivery(Flow::of($this->guard, Surface::REGISTRATION), RegistrationLink::purpose(), account: null, address: $address);
        } catch (Throttled) {
            return false;
        }

        return true;
    }
}
