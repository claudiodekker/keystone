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
 * Attempt a sign-in with a credential type's proof, from the submitted input to a signed-in session or one refusal.
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
        protected TimingFloor $floor,
    ) {
        //
    }

    /**
     * Attempt to sign in with the named type and the submitted input.
     *
     * Every refusal takes the timing floor and comes back the same; a sign-in answers as soon as it completes.
     *
     * @param  array<string, mixed>  $input
     */
    public function attempt(string $type, array $input): SignedIn|Refused
    {
        $credentialType = Keystone::credentialTypes()->find($type);

        return $this->floor->hold(
            fn () => $this->prove($credentialType, $input),
            fn (SignedIn|Refused $outcome) => $outcome instanceof SignedIn,
        );
    }

    /**
     * Sign in the account the identifier names when the type proves one of its credentials.
     *
     * The type is called even when the identifier names no account, so it does the same work either way.
     *
     * @param  array<string, mixed>  $input
     */
    protected function prove(CredentialType $type, array $input): SignedIn|Refused
    {
        $account = $this->accounts->resolve($input['identifier']);

        $verification = new Verification(
            surface: Surface::SIGN_IN,
            input: $input,
            credentials: $this->credentials($type, $account),
        );

        $proof = $type->verify($verification);

        if ($account === null || ! $proof->isProven()) {
            return new Refused;
        }

        return new SignedIn($this->session->signIn($account));
    }

    /**
     * Get the account's credentials of the type, none when there is no account.
     *
     * @return list<Credential>
     */
    protected function credentials(CredentialType $type, ?KeystoneUser $account): array
    {
        if ($account === null) {
            return [];
        }

        $credentials = UserCredential::query()
            ->where('user_id', $account->getAuthIdentifier())
            ->where('type', $type->name())
            ->get();

        return array_values($credentials->map(fn (UserCredential $credential) => $credential->toCredential())->all());
    }
}
