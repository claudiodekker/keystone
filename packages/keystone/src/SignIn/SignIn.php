<?php

namespace ClaudioDekker\Keystone\SignIn;

use ClaudioDekker\Keystone\Accounts\AccountLookup;
use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\Credential;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Methods\Verification;
use ClaudioDekker\Keystone\Models\UserCredential;
use ClaudioDekker\Keystone\Session\SessionState;

/**
 * Attempt a sign-in with a credential type's proof, from the submitted input to a signed-in session.
 *
 * @internal
 */
class SignIn
{
    /**
     * Create the sign-in.
     */
    public function __construct(
        protected AccountLookup $accounts,
        protected SessionState $session,
    ) {
        //
    }

    /**
     * Attempt to sign in with the named type and the submitted input.
     *
     * @param  array<string, mixed>  $input
     */
    public function attempt(string $type, array $input): SignedIn|Refused
    {
        $credentialType = Keystone::credentialTypes()->find($type);

        $account = $this->accounts->resolve($input['identifier']);

        $verification = new Verification(
            surface: Surface::SIGN_IN,
            input: $input,
            credentials: $this->credentials($credentialType, $account),
        );

        $proof = $credentialType->verify($verification);

        if (! $proof->isProven()) {
            return new Refused;
        }

        return new SignedIn($this->session->signIn($account));
    }

    /**
     * Get the account's credentials of the type.
     *
     * @return list<Credential>
     */
    protected function credentials(CredentialType $type, KeystoneUser $account): array
    {
        $credentials = UserCredential::query()
            ->where('user_id', $account->getAuthIdentifier())
            ->where('type', $type->name())
            ->get();

        return array_values($credentials->map(fn (UserCredential $credential) => $credential->toCredential())->all());
    }
}
