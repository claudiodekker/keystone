<?php

namespace ClaudioDekker\Keystone\SignIn;

use ClaudioDekker\Keystone\Accounts\AccountLookup;
use ClaudioDekker\Keystone\Exceptions\ThrottledException;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\RateLimits\Limiter;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
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
        protected Proofs $proofs,
        protected TimingFloor $floor,
        protected ValidationFactory $validation,
    ) {
        //
    }

    /**
     * Attempt to sign in with the type and the submitted input, remembering the browser once signed in when asked.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ThrottledException
     * @throws ValidationException
     */
    public function attempt(CredentialType $type, array $input, bool $remember = false): SignedIn|Held|Refused
    {
        $validated = $this->validate($type, $input);

        return $this->floor->hold(
            fn () => $this->prove($type, $validated, $remember),
            fn (SignedIn|Held|Refused $outcome) => ! $outcome instanceof Refused,
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
     * Sign in, or hold for what it owes, the account the identifier names when the type proves one of its credentials.
     *
     * @param  array<string, mixed>  $validated
     *
     * @throws ThrottledException
     */
    protected function prove(CredentialType $type, array $validated, bool $remember): SignedIn|Held|Refused
    {
        $account = $this->accounts->resolve($validated['identifier']);

        $attempt = $this->limiter->takeFailedAttempt($type, Surface::SIGN_IN, $account ?? $validated['identifier']);

        $input = Arr::except($validated, 'identifier');
        $answer = $this->proofs->verify($type, Surface::SIGN_IN, $account, $input, $attempt);

        if ($account === null || ! $this->decision->proves($answer->proof, $type, $account)) {
            return $this->proofs->refuse($account, $answer);
        }

        $stage = $this->decision->owed($account, $type);

        if ($stage !== null) {
            return $this->proofs->hold($account, $answer, $stage);
        }

        return $this->proofs->signIn($account, $answer, remember: $remember);
    }
}
