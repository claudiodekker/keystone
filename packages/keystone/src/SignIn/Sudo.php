<?php

namespace ClaudioDekker\Keystone\SignIn;

use ClaudioDekker\Keystone\Accounts\Holdings;
use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Exceptions\ThrottledException;
use ClaudioDekker\Keystone\Http\Pages\SudoPage;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\RateLimits\Limiter;
use ClaudioDekker\Keystone\Session\SessionState;
use ClaudioDekker\Keystone\Session\SudoInProgress;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Validation\ValidationException;

/**
 * @internal
 */
class Sudo
{
    /**
     * Create the sudo replay.
     */
    public function __construct(
        protected SignInDecision $decision,
        protected Holdings $holdings,
        protected Limiter $limiter,
        protected Proofs $proofs,
        protected SessionState $session,
        protected TimingFloor $floor,
        protected CredentialTypes $types,
        protected ValidationFactory $validation,
    ) {
        //
    }

    /**
     * Find the type that answers the sudo-in-progress's step by its name.
     */
    public function type(string $name): ?CredentialType
    {
        return $this->types->find($name, Surface::SIGN_IN);
    }

    /**
     * Get the surface the session's sudo-in-progress's step verifies on, sign-in's when it holds none.
     */
    public function surface(): Surface
    {
        return $this->session->sudoInProgress()?->surface() ?? Surface::SIGN_IN;
    }

    /**
     * Open the session's sudo-in-progress at its step: the types serving sign-in the account holds, the first one preselected.
     *
     * @throws AuthenticationException
     */
    public function open(): SudoPage|NoSudoInProgress
    {
        $account = $this->account();

        if ($this->session->sudoInProgress() === null) {
            return new NoSudoInProgress('/');
        }

        $held = $this->holdings->signInTypes($account);

        $types = collect($this->types->shapes(Surface::SIGN_IN))
            ->filter(fn (array $type) => in_array($type['name'], $held, true))
            ->values()
            ->all();

        return new SudoPage(
            types: $types,
            preselected: $types[0]['name'] ?? null,
        );
    }

    /**
     * Answer the session's sudo-in-progress with the type and the submitted input.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws AuthenticationException
     * @throws ThrottledException
     * @throws ValidationException
     */
    public function answer(CredentialType $type, array $input): SudoGranted|Refused|NoSudoInProgress
    {
        $account = $this->account();
        $sudo = $this->session->sudoInProgress();

        if ($sudo === null) {
            return new NoSudoInProgress('/');
        }

        $validated = $this->validation->make($input, $type->rules($sudo->surface()))->validate();

        return $this->floor->hold(
            fn () => $this->prove($type, $validated, $sudo, $account),
            fn (SudoGranted|Refused $outcome) => $outcome instanceof SudoGranted,
        );
    }

    /**
     * Grant the session sudo when the type proves one of the account's credentials.
     *
     * @param  array<string, mixed>  $validated
     *
     * @throws ThrottledException
     */
    protected function prove(CredentialType $type, array $validated, SudoInProgress $sudo, KeystoneUser $account): SudoGranted|Refused
    {
        $surface = $sudo->surface();
        $attempt = $this->limiter->takeFailedAttempt($type, $surface, $account);

        $answer = $this->proofs->verify($type, $surface, $account, $validated, $attempt);

        if (! $this->decision->proves($answer->proof, $type, $account)) {
            return $this->proofs->refuseSudo($account, $answer);
        }

        return $this->proofs->grantSudo($account, $answer, $sudo, $this->session->subnet());
    }

    /**
     * Get the signed-in account, refusing a guest.
     *
     * @throws AuthenticationException
     */
    protected function account(): KeystoneUser
    {
        return $this->session->account() ?? throw new AuthenticationException;
    }
}
