<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Actions\CreateAccount;
use ClaudioDekker\Keystone\Exceptions\AddressTaken;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\Proof;
use ClaudioDekker\Keystone\Methods\Surface;
use Throwable;

/**
 * @internal
 */
class RegistrationAttempt
{
    /**
     * Create a new registration attempt instance.
     */
    public function __construct(
        protected KeystoneGuard $guard,
        protected CreateAccount $createAccount,
        protected SecurityEventRecorder $recorder = new SecurityEventRecorder,
    ) {
        //
    }

    /**
     * Let the type make the account's first credential from the input, create the account holding the address the session registers, then sign it in or hold it for the enrollment it owes.
     *
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>  $input
     */
    public function attempt(CredentialType $type, array $profile, #[\SensitiveParameter] array $input, string $intendedUrl): RegistrationResult
    {
        $proof = $this->prove($type, $input);

        if ($proof->enrolled === null) {
            return RegistrationResult::REFUSED;
        }

        $registration = $this->guard->registration();

        if ($registration === null) {
            return RegistrationResult::EXPIRED;
        }

        try {
            $account = (new AccountCreation($this->guard, $this->createAccount, $this->recorder))->create($profile, $registration->address, $type, $proof->enrolled);
        } catch (AddressTaken) {
            $this->guard->endRegistration();

            return RegistrationResult::ADDRESS_TAKEN;
        }

        $entry = new RegistrationEntry($this->guard, $account, $type, $intendedUrl);

        return match ((new AcceptedProof($this->recorder))->conclude($entry, $account, Flow::REGISTRATION, $type->name())) {
            Demand::SIGN_IN => RegistrationResult::SIGNED_IN,
            Demand::ENROLLMENT => RegistrationResult::ENROLLMENT_OWED,
            default => $this->refuseBarred(),
        };
    }

    /**
     * Let the type make the first credential from the input, turning any failure into a rejection.
     *
     * @param  array<string, mixed>  $input
     */
    protected function prove(CredentialType $type, #[\SensitiveParameter] array $input): Proof
    {
        try {
            return $type->verify(Surface::REGISTRATION, $input, []);
        } catch (Throwable $e) {
            report($e);

            return Proof::rejected('keystone.verify_failed');
        }
    }

    /**
     * End the registration of an account the app's action created barred, which signs in no more than a barred account at sign-in does.
     */
    protected function refuseBarred(): RegistrationResult
    {
        $this->guard->endRegistration();

        return RegistrationResult::BARRED;
    }
}
