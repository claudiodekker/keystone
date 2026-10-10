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

    /**
     * Take the address from every account holding it, once an account is about to verify it, without telling them.
     */
    public function settle(string $address): void
    {
        $users = $this->guard->userModel();
        $address = Addresses::normalize($address);

        $holders = $users->getConnection()->table('user_emails')->where('address', $address)->distinct()->pluck('user_id');

        /** @var iterable<Model&KeystoneUser> $losers */
        $losers = $users->newQueryWithoutScopes()->whereKey($holders)->orderBy($users->getKeyName())->get();

        $changes = new AccountChanges($this->guard, $this->recorder);

        foreach ($losers as $loser) {
            $changes->change($loser, fn (AccountChange $change) => $change->loseAddress($address));
        }
    }
}
