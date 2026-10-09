<?php

namespace ClaudioDekker\Keystone;

use Illuminate\Database\Eloquent\Model;

/**
 * @internal
 */
class AddressClaims
{
    /**
     * Create a new address claims instance.
     */
    public function __construct(
        protected KeystoneGuard $guard,
        protected SecurityEventRecorder $recorder = new SecurityEventRecorder,
    ) {
        //
    }

    /**
     * Record address.claim_attempted on every active account holding the address verified or counting as verified, alerting each.
     */
    public function record(string $address, Flow $flow): void
    {
        $claimants = (new Addresses($this->guard->userModel()))->claimants($address);

        /** @var iterable<Model&KeystoneUser> $owners */
        $owners = $this->guard->userModel()->newQueryWithoutScopes()->whereKey($claimants)->get();

        foreach ($owners as $owner) {
            $this->recorder->record(SecurityEventType::ADDRESS_CLAIM_ATTEMPTED, account: $owner, flow: $flow->value);
        }
    }
}
