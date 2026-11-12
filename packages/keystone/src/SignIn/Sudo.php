<?php

namespace ClaudioDekker\Keystone\SignIn;

use ClaudioDekker\Keystone\Accounts\Holdings;
use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Http\Pages\SudoPage;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Session\SessionState;
use Illuminate\Auth\AuthenticationException;

/**
 * @internal
 */
class Sudo
{
    /**
     * Create the sudo replay.
     */
    public function __construct(
        protected Holdings $holdings,
        protected SessionState $session,
        protected CredentialTypes $types,
    ) {
        //
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
     * Get the signed-in account, refusing a guest.
     *
     * @throws AuthenticationException
     */
    protected function account(): KeystoneUser
    {
        return $this->session->account() ?? throw new AuthenticationException;
    }
}
