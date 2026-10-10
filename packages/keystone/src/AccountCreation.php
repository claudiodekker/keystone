<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Actions\CreateAccount;
use ClaudioDekker\Keystone\Exceptions\AddressTaken;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\EnrolledCredential;
use ClaudioDekker\Keystone\Methods\StoredCredential;
use Illuminate\Database\Eloquent\Model;

/**
 * @internal
 */
class AccountCreation
{
    /**
     * Create a new account creation instance.
     */
    public function __construct(
        protected KeystoneGuard $guard,
        protected CreateAccount $createAccount,
        protected SecurityEventRecorder $recorder = new SecurityEventRecorder,
    ) {
        //
    }

    /**
     * Create the account in one transaction, holding the registration's address as its primary one and the enrolled credential, and record account.registered once it commits.
     *
     * The address is stored verified only when the registration proved it.
     *
     * @param  array<string, mixed>  $profile
     * @return Model&KeystoneUser
     *
     * @throws AddressTaken
     */
    public function create(array $profile, Registering $registration, CredentialType $type, EnrolledCredential $credential): Model
    {
        $users = $this->guard->userModel();
        $address = $registration->address;

        return $users->getConnection()->transaction(function () use ($users, $profile, $registration, $address, $type, $credential) {
            if ((new Addresses($users))->claimants($address) !== []) {
                throw new AddressTaken;
            }

            if ($registration->verified) {
                (new AddressClaims($this->guard, $this->recorder))->settle($address);
            }

            $account = $this->createAccount->handle($profile);

            $changes = new AccountChanges($this->guard, $this->recorder);

            return $changes->change($account, function (AccountChange $change) use ($registration, $address, $type, $credential) {
                if ($registration->verified) {
                    $change->addVerifiedAddress($address);
                } else {
                    $change->addUnverifiedAddress($address);
                }

                $id = $change->addCredential($type, identifier: $credential->identifier, secret: $credential->secret, label: $credential->label);

                $change->record(
                    SecurityEventType::ACCOUNT_REGISTERED,
                    flow: Flow::REGISTRATION->value,
                    credentialType: $type->name(),
                    credential: new StoredCredential($id, identifier: null, secret: null, label: $credential->label),
                    recipients: [$address],
                );

                return $change->account;
            });
        });
    }
}
