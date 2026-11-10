<?php

namespace ClaudioDekker\Keystone\SignIn;

use ClaudioDekker\Keystone\Accounts\AccountLookup;
use ClaudioDekker\Keystone\Accounts\CredentialEpoch;
use ClaudioDekker\Keystone\Accounts\Holdings;
use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\Proof;
use ClaudioDekker\Keystone\Models\UserCredential;
use ClaudioDekker\Keystone\Session\PendingSignIn;
use ClaudioDekker\Keystone\Session\Stage;

/**
 * @internal
 */
class SignInDecision
{
    /**
     * Create the decision.
     */
    public function __construct(
        protected AccountLookup $accounts,
        protected Holdings $holdings,
    ) {
        //
    }

    /**
     * Determine if the proof proves a credential of the type that the account holds, read again from its row; a rejection names none.
     */
    public function proves(Proof $proof, CredentialType $type, KeystoneUser $account): bool
    {
        return UserCredential::query()
            ->whereKey($proof->credential)
            ->where('type', $type->name())
            ->where('user_id', $account->getAuthIdentifier())
            ->exists();
    }

    /**
     * Get the stage a sign-in by the proven first factor is held at, or null when it owes nothing.
     */
    public function owed(KeystoneUser $account, CredentialType $firstFactor): ?Stage
    {
        if ($firstFactor->isMultiFactor()) {
            return null;
        }

        if (! $this->isOwed($account, Stage::CHALLENGE)) {
            return null;
        }

        return Stage::CHALLENGE;
    }

    /**
     * Get the account the pending sign-in still holds at its stage, or why the hold ends.
     *
     * A hold ends voided when its account's credential epoch moved or the account was barred since the hold, and unneeded when the account no longer owes the stage.
     */
    public function resume(PendingSignIn $pending): KeystoneUser|HoldEnded
    {
        $account = $this->accounts->find($pending->account);

        if ($account === null || CredentialEpoch::of($pending->account) !== $pending->epoch) {
            return HoldEnded::VOIDED;
        }

        if (! $this->isOwed($account, $pending->stage)) {
            return HoldEnded::UNNEEDED;
        }

        return $account;
    }

    /**
     * Determine if the proof answers the pending sign-in's challenge: a credential the held account holds, of another type than the first factor's.
     */
    public function answers(Proof $proof, CredentialType $type, PendingSignIn $pending, KeystoneUser $account): bool
    {
        return $type->name() !== $pending->firstFactor && $this->proves($proof, $type, $account);
    }

    /**
     * Determine if the account owes the stage as it stands now.
     */
    protected function isOwed(KeystoneUser $account, Stage $stage): bool
    {
        return match ($stage) {
            Stage::CHALLENGE => $this->holdings->holdsSecondFactor($account),
        };
    }
}
