<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Notifications\EmailedLinkMail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;

/**
 * @internal
 *
 * @phpstan-consistent-constructor
 */
readonly class RegistrationLink implements EmailedLink
{
    /**
     * Create a new registration link instance for the address, as Keystone stores it.
     */
    public function __construct(
        public string $address,
    ) {
        //
    }

    /**
     * Get the purpose naming the link's subkeys and mail kind.
     */
    public static function purpose(): string
    {
        return 'registration';
    }

    /**
     * Get the name of the route that opens the link's show step.
     */
    public static function route(): string
    {
        return 'register.verify';
    }

    /**
     * Rebuild the link from a payload holding one address as Keystone stores it.
     */
    public static function fromPayload(array $payload): ?static
    {
        $address = $payload['address'] ?? null;

        if (! is_string($address) || $address === '' || Addresses::normalize($address) !== $address) {
            return null;
        }

        return new static($address);
    }

    /**
     * Get the address the link proves.
     */
    public function payload(): array
    {
        return ['address' => $this->address];
    }

    /**
     * Get no account: nobody holds the address yet.
     */
    public function account(KeystoneGuard $guard): ?Model
    {
        return null;
    }

    /**
     * Determine if no active account holds the address verified or counting as verified.
     */
    public function destinationHolds(KeystoneGuard $guard): bool
    {
        return (new Addresses($guard->userModel()))->claimants($this->address) === [];
    }

    /**
     * Alert every active account that came to hold the address since the link was mailed.
     */
    public function destinationLost(KeystoneGuard $guard): void
    {
        (new AddressClaims($guard))->record($this->address, Flow::of($guard, Surface::REGISTRATION));
    }

    /**
     * Get the mail carrying the link to the address.
     */
    public function mail(IssuedLink $issued): Notification
    {
        return new EmailedLinkMail(static::purpose(), $issued);
    }
}
