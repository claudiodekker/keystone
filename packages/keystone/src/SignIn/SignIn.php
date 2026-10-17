<?php

namespace ClaudioDekker\Keystone\SignIn;

use ClaudioDekker\Keystone\Accounts\AccountLookup;
use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Exceptions\ThrottledException;
use ClaudioDekker\Keystone\Methods\Credential;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\Proof;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Methods\Verification;
use ClaudioDekker\Keystone\Models\UserCredential;
use ClaudioDekker\Keystone\RateLimits\CountedAttempt;
use ClaudioDekker\Keystone\RateLimits\Limiter;
use ClaudioDekker\Keystone\SecurityEvents\Recorder;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use ClaudioDekker\Keystone\Session\SessionState;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Throwable;

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
        protected Limiter $limiter,
        protected Recorder $recorder,
        protected SessionState $session,
        protected TimingFloor $floor,
        protected ValidationFactory $validation,
    ) {
        //
    }

    /**
     * Attempt to sign in with the type and the submitted input.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ThrottledException
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
            ...$type->rules(Surface::SIGN_IN),
            'identifier' => ['required', 'string', 'max:255'],
        ];

        return $this->validation->make($input, $rules)->validate();
    }

    /**
     * Sign in the account the identifier names when the type proves one of its credentials.
     *
     * @param  array<string, mixed>  $validated
     *
     * @throws ThrottledException
     */
    protected function prove(CredentialType $type, array $validated): SignedIn|Refused
    {
        $account = $this->accounts->resolve($validated['identifier']);

        $attempt = $this->limiter->takeFailedAttempt($type, Surface::SIGN_IN, $account, $validated['identifier']);

        $proof = $this->verify($type, $account, $validated, $attempt);

        $owner = $this->decision->decide($proof, $type, $account);

        if ($owner === null) {
            $this->recorder->record(
                SecurityEventType::PROOF_REJECTED,
                account: $account,
                surface: Surface::SIGN_IN,
                credentialType: $type,
            );

            return new Refused;
        }

        $redirectTo = $this->session->signIn($owner);

        $this->limiter->giveBack($attempt);

        $this->recorder->record(
            SecurityEventType::SIGNED_IN,
            account: $owner,
            surface: Surface::SIGN_IN,
            credentialType: $type,
            credential: $proof->credential,
        );

        return new SignedIn($redirectTo);
    }

    /**
     * Let the type verify the input against the account's credentials, giving the attempt back when the type throws.
     *
     * @param  array<string, mixed>  $validated
     */
    protected function verify(CredentialType $type, ?KeystoneUser $account, array $validated, CountedAttempt $attempt): Proof
    {
        $verification = new Verification(
            surface: Surface::SIGN_IN,
            input: Arr::except($validated, 'identifier'),
            credentials: $this->credentials($type, $account),
        );

        try {
            return $type->verify($verification);
        } catch (Throwable $exception) {
            $this->limiter->giveBack($attempt);

            throw $exception;
        }
    }

    /**
     * Get the account's credentials of the type, none when there is no account.
     *
     * @return array<int, Credential>
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

        return $credentials
            ->map(fn (UserCredential $credential) => $credential->toCredential())
            ->values()
            ->all();
    }
}
