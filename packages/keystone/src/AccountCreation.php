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
     * Create the account in one transaction, holding the address as its verified primary one and the enrolled credential, and record account.registered once it commits.
     *
     * @param  array<string, mixed>  $profile
     * @return Model&KeystoneUser
     *
     * @throws AddressTaken
     */
    public function create(array $profile, string $address, CredentialType $type, EnrolledCredential $credential): Model
    {
        $users = $this->guard->userModel();

        return $users->getConnection()->transaction(function () use ($users, $profile, $address, $type, $credential) {
            if ((new Addresses($users))->claimants($address) !== []) {
                throw new AddressTaken;
            }

            $account = $this->createAccount->handle($profile);

            $changes = new AccountChanges($this->guard, $this->recorder);

            return $changes->change($account, function (AccountChange $change) use ($address, $type, $credential) {
                $change->addVerifiedAddress($address);

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
