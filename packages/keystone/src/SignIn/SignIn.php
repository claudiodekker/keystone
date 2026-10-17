<?php

namespace ClaudioDekker\Keystone\SignIn;

use ClaudioDekker\Keystone\Accounts\AccountLookup;
use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Methods\Credential;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Methods\Verification;
use ClaudioDekker\Keystone\Models\UserCredential;
use ClaudioDekker\Keystone\Session\SessionState;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

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
        protected SignInDecision $decision,
        protected SessionState $session,
        protected TimingFloor $floor,
        protected ValidationFactory $validation,
    ) {
        //
    }

    /**
     * Attempt to sign in with the type and the submitted input.
     *
     * Every refusal takes the timing floor and comes back the same; a sign-in answers as soon as it completes.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function attempt(CredentialType $type, array $input): SignedIn|Refused
    {
        $validated = $this->validate($type, $input);

        return $this->floor->hold(
            fn () => $this->prove($type, $validated),
            fn (SignedIn|Refused $outcome) => $outcome instanceof SignedIn,
        );
    }

    /**
     * Validate the identifier and the type's own fields, and get only those.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    protected function validate(CredentialType $type, array $input): array
    {
        $rules = [
            'identifier' => ['required', 'string', 'max:255'],
            ...$type->rules(Surface::SIGN_IN),
        ];

        return $this->validation->make($input, $rules)->validate();
    }

    /**
     * Sign in the account the identifier names when the type proves one of its credentials.
     *
     * The type is called even when the identifier names no account, so it does the same work either way.
     *
     * @param  array<string, mixed>  $validated
     */
    protected function prove(CredentialType $type, array $validated): SignedIn|Refused
    {
        $account = $this->accounts->resolve($validated['identifier']);

        $verification = new Verification(
            surface: Surface::SIGN_IN,
            input: Arr::except($validated, 'identifier'),
            credentials: $this->credentials($type, $account),
        );

        $proof = $type->verify($verification);

        $owner = $this->decision->decide($proof, $type, $account);

        if ($owner === null) {
            return new Refused;
        }

        return new SignedIn($this->session->signIn($owner));
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
